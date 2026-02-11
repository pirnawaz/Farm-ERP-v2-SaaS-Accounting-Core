<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTenantModulesRequest;
use App\Models\Module;
use App\Models\TenantModule;
use App\Services\ModuleDependencies;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantModuleController extends Controller
{
    public function __construct(
        protected ModuleDependencies $moduleDependencies
    ) {}

    /**
     * List all modules with enabled/status for the current tenant.
     * Uses effective enabled set (core + persisted ENABLED + transitive deps) so e.g. Land shows
     * ENABLED when required by core Projects & Crop Cycles. Self-heals by upserting missing
     * tenant_modules for non-core dependencies so DB stays consistent.
     * GET /api/tenant/modules
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $modules = Module::orderBy('sort_order')->orderBy('name')->get();
        $effectiveEnabled = $this->moduleDependencies->getEffectiveEnabledModulesForTenant($tenantId);
        $userId = $request->attributes->get('user_id') ?? $request->header('X-User-Id');

        DB::transaction(function () use ($tenantId, $effectiveEnabled, $modules, $userId) {
            $pivots = TenantModule::where('tenant_id', $tenantId)
                ->get()
                ->keyBy('module_id');
            $modulesByKey = $modules->keyBy('key');
            foreach ($effectiveEnabled as $key) {
                $module = $modulesByKey->get($key);
                if (!$module || $module->is_core) {
                    continue;
                }
                $pivot = $pivots->get($module->id);
                if ($pivot && $pivot->status === 'ENABLED') {
                    continue;
                }
                $pivot = TenantModule::firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'module_id' => $module->id,
                    ],
                    [
                        'status' => 'ENABLED',
                        'enabled_at' => now(),
                        'disabled_at' => null,
                        'enabled_by_user_id' => $userId,
                    ]
                );
                $pivot->update([
                    'status' => 'ENABLED',
                    'enabled_at' => now(),
                    'disabled_at' => null,
                    'enabled_by_user_id' => $userId,
                ]);
            }
        });

        $keyToName = $modules->keyBy('key')->map(fn (Module $m) => $m->name)->all();

        $items = $modules->map(function (Module $m) use ($effectiveEnabled) {
            $enabled = in_array($m->key, $effectiveEnabled, true);
            $tier = $this->moduleDependencies->getTier($m->key);
            $requiredBy = $this->moduleDependencies->findDependents($m->key, $effectiveEnabled);
            return [
                'key' => $m->key,
                'name' => $m->name,
                'description' => $m->description,
                'is_core' => $m->is_core,
                'tier' => $tier,
                'sort_order' => $m->sort_order,
                'enabled' => $enabled,
                'status' => $enabled ? 'ENABLED' : 'DISABLED',
                'required_by' => $requiredBy,
            ];
        });

        return response()->json([
            'modules' => $items->values(),
            'key_to_name' => $keyToName,
        ]);
    }

    /**
     * Update enabled/disabled state of modules for the current tenant.
     * Enabling a module auto-enables all hard dependencies. Disabling is blocked if dependents are enabled.
     * PUT /api/tenant/modules
     */
    public function update(UpdateTenantModulesRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $userId = $request->attributes->get('user_id') ?? $request->header('X-User-Id');
        $payload = $request->validated('modules');

        $moduleKeys = collect($payload)->pluck('key')->all();
        $modulesByKey = Module::whereIn('key', $moduleKeys)->get()->keyBy('key');
        foreach ($moduleKeys as $key) {
            if (!$modulesByKey->has($key)) {
                return response()->json(['error' => "Module not found: {$key}"], 404);
            }
        }

        $currentEffective = $this->moduleDependencies->getEffectiveEnabledModulesForTenant($tenantId);
        $initialEnabled = $currentEffective;

        // Build desired state: start from current effective (all true), override with payload, then expand deps
        $desired = array_fill_keys($currentEffective, true);
        foreach ($payload as $item) {
            $desired[$item['key']] = $item['enabled'];
        }
        foreach (array_keys($desired) as $key) {
            if ($desired[$key]) {
                foreach ($this->moduleDependencies->resolveDependencies($key) as $dep) {
                    $desired[$dep] = true;
                }
            }
        }

        // Track which modules were auto-enabled per explicitly requested key (for toast)
        $autoEnabled = [];
        foreach ($payload as $item) {
            if (!$item['enabled']) {
                continue;
            }
            $key = $item['key'];
            $allRequired = $this->moduleDependencies->resolveDependencies($key);
            $alsoEnabled = array_values(array_filter($allRequired, function ($dep) use ($initialEnabled, $key) {
                return $dep !== $key && !in_array($dep, $initialEnabled, true);
            }));
            if (count($alsoEnabled) > 0) {
                $autoEnabled[$key] = $alsoEnabled;
            }
        }

        // Validate disables: core cannot be disabled; dependents must be disabled first
        $desiredEnabledKeys = array_keys(array_filter($desired));
        foreach ($payload as $item) {
            if ($item['enabled']) {
                continue;
            }
            $key = $item['key'];
            $module = $modulesByKey->get($key);
            if ($module && $module->is_core) {
                return response()->json([
                    'error' => 'MODULE_DEPENDENCY',
                    'message' => 'Core modules cannot be disabled.',
                    'blockers' => [],
                ], 422);
            }
            $blockers = $this->moduleDependencies->findDependents($key, $desiredEnabledKeys);
            if (count($blockers) > 0) {
                return response()->json([
                    'error' => 'MODULE_DEPENDENCY',
                    'message' => 'Cannot disable this module because the following enabled modules depend on it: ' . implode(', ', $blockers) . '. Disable those first.',
                    'blockers' => $blockers,
                ], 422);
            }
        }

        try {
            DB::transaction(function () use ($tenantId, $userId, $desired, $modulesByKey) {
                $allKeys = array_keys($desired);
                $modules = Module::whereIn('key', $allKeys)->get();
                foreach ($modules as $module) {
                    if ($module->is_core) {
                        continue;
                    }
                    $wantEnabled = $desired[$module->key] ?? false;
                    $pivot = TenantModule::firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'module_id' => $module->id,
                        ],
                        [
                            'status' => 'ENABLED',
                            'enabled_at' => now(),
                            'disabled_at' => null,
                            'enabled_by_user_id' => $userId,
                        ]
                    );
                    if ($wantEnabled) {
                        $pivot->update([
                            'status' => 'ENABLED',
                            'enabled_at' => now(),
                            'disabled_at' => null,
                            'enabled_by_user_id' => $userId,
                        ]);
                    } else {
                        $pivot->update([
                            'status' => 'DISABLED',
                            'disabled_at' => now(),
                        ]);
                    }
                }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'UPDATE_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }

        $response = $this->index($request);
        $data = $response->getData(true);
        $data['auto_enabled'] = $autoEnabled;
        return response()->json($data);
    }
}
