<?php

namespace App\Http\Controllers\Platform;

use App\Domains\Platform\Modules\ModuleDependencyService;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\PlanModules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformTenantModulesController extends Controller
{
    public function __construct(
        protected ModuleDependencyService $moduleDependencyService,
        protected PlanModules $planModules
    ) {}

    /**
     * List all modules with enabled/status for a tenant. Platform admin only.
     * GET /api/platform/tenants/{tenantId}/modules
     */
    public function index(string $tenantId, ?array $autoEnabledBy = null): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $planKey = $tenant->plan_key;
        $allowedKeys = $this->planModules->getAllowedModuleKeysForPlan($planKey);
        $tiers = config('modules.tiers', []);
        $dependencies = config('modules.dependencies', []);

        $modules = Module::orderBy('sort_order')->orderBy('name')->get();
        $pivots = TenantModule::where('tenant_id', $tenantId)->get()->keyBy('module_id');

        $items = $modules->map(function (Module $m) use ($pivots, $allowedKeys, $tiers, $dependencies, $autoEnabledBy) {
            $pivot = $pivots->get($m->id);
            $enabled = $m->is_core || ($pivot && $pivot->status === 'ENABLED');
            $allowedByPlan = $m->is_core || in_array($m->key, $allowedKeys, true);
            $item = [
                'key' => $m->key,
                'name' => $m->name,
                'description' => $m->description,
                'is_core' => $m->is_core,
                'sort_order' => $m->sort_order,
                'enabled' => $enabled,
                'status' => $enabled ? 'ENABLED' : 'DISABLED',
                'tier' => $tiers[$m->key] ?? 'OPTIONAL',
                'dependencies' => $dependencies[$m->key] ?? [],
                'allowed_by_plan' => $allowedByPlan,
                'enabled_at' => $pivot?->enabled_at?->toIso8601String(),
                'disabled_at' => $pivot?->disabled_at?->toIso8601String(),
            ];
            if ($autoEnabledBy !== null && isset($autoEnabledBy[$m->key])) {
                $item['auto_enabled_by'] = array_values(array_unique($autoEnabledBy[$m->key]));
            }
            return $item;
        });

        return response()->json([
            'modules' => $items->values(),
            'plan_key' => $planKey,
        ]);
    }

    /**
     * Update enabled state of modules for a tenant.
     * Enforcing: plan allow-list, CORE cannot disable, dependencies auto-enabled, disable blocked if dependents enabled.
     * PUT /api/platform/tenants/{tenantId}/modules
     * Body: { modules: [ { key: string, enabled: boolean } ] }
     */
    public function update(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $planKey = $tenant->plan_key;
        $allowedKeys = $this->planModules->getAllowedModuleKeysForPlan($planKey);
        $tiers = config('modules.tiers', []);

        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.key' => ['required', 'string', 'exists:modules,key'],
            'modules.*.enabled' => ['required', 'boolean'],
        ]);

        $userId = $request->header('X-User-Id');
        $allModules = Module::orderBy('sort_order')->orderBy('name')->get();
        $modulesByKey = $allModules->keyBy('key');
        $pivots = TenantModule::where('tenant_id', $tenantId)->get()->keyBy('module_id');

        $currentEnabledMap = [];
        foreach ($allModules as $m) {
            $currentEnabledMap[$m->key] = $m->is_core || ($pivots->get($m->id) && $pivots->get($m->id)->status === 'ENABLED');
        }

        $requestedMap = [];
        foreach ($validated['modules'] as $item) {
            $requestedMap[$item['key']] = (bool) $item['enabled'];
            if ($item['enabled']) {
                $mod = $modulesByKey->get($item['key']);
                if ($mod && !$mod->is_core && !in_array($item['key'], $allowedKeys, true)) {
                    return response()->json([
                        'error' => 'MODULE_NOT_ALLOWED_BY_PLAN',
                        'message' => "Module {$item['key']} is not allowed on plan " . ($planKey ?: 'null') . '.',
                    ], 422);
                }
            }
        }

        $finalMap = $currentEnabledMap;
        foreach ($requestedMap as $key => $enabled) {
            $finalMap[$key] = $enabled;
        }

        foreach (array_keys($finalMap) as $key) {
            if ($finalMap[$key] !== false) {
                continue;
            }
            $requestedDisable = isset($requestedMap[$key]) && $requestedMap[$key] === false;
            $wasEnabled = ($currentEnabledMap[$key] ?? false) === true;
            if (!$requestedDisable && !$wasEnabled) {
                continue;
            }
            if (($tiers[$key] ?? 'OPTIONAL') === 'CORE') {
                return response()->json([
                    'error' => 'CORE_CANNOT_DISABLE',
                    'message' => "Module {$key} is CORE and cannot be disabled.",
                ], 422);
            }
            try {
                $dependents = $this->moduleDependencyService->getTransitiveDependents($key);
            } catch (\RuntimeException $e) {
                return response()->json([
                    'error' => 'MODULE_DEPENDENCY_CYCLE',
                    'message' => $e->getMessage(),
                ], 422);
            }
            $enabledDependents = array_filter($dependents, function ($d) use ($finalMap, $tiers) {
                $enabled = ($finalMap[$d] ?? false) === true;
                if ($enabled) {
                    return true;
                }
                return ($tiers[$d] ?? 'OPTIONAL') === 'CORE';
            });
            if (count($enabledDependents) > 0) {
                return response()->json([
                    'error' => 'DISABLE_BLOCKED_BY_DEPENDENTS',
                    'message' => 'Cannot disable ' . $key . ' because enabled module(s) depend on it: ' . implode(', ', array_values($enabledDependents)) . '.',
                ], 422);
            }
        }

        $autoEnabledBy = [];

        foreach (array_keys($finalMap) as $key) {
            if ($finalMap[$key] !== true) {
                continue;
            }
            try {
                $deps = $this->moduleDependencyService->getTransitiveDependencies($key);
            } catch (\RuntimeException $e) {
                return response()->json([
                    'error' => 'MODULE_DEPENDENCY_CYCLE',
                    'message' => $e->getMessage(),
                ], 422);
            }
            foreach ($deps as $dep) {
                if (!$this->planModules->isModuleAllowedForPlan($dep, $planKey)) {
                    return response()->json([
                        'error' => 'DEPENDENCY_NOT_ALLOWED_BY_PLAN',
                        'message' => "Cannot enable {$key} because dependency {$dep} is not allowed on plan " . ($planKey ?: 'null') . '.',
                    ], 422);
                }
                $finalMap[$dep] = true;
                if (!isset($autoEnabledBy[$dep])) {
                    $autoEnabledBy[$dep] = [];
                }
                $autoEnabledBy[$dep][] = $key;
            }
        }

        try {
            DB::transaction(function () use ($tenantId, $userId, $finalMap, $allModules) {
                foreach ($allModules as $module) {
                    if ($module->is_core) {
                        continue;
                    }
                    $wantEnabled = ($finalMap[$module->key] ?? false) === true;
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

        return $this->index($tenantId, $autoEnabledBy);
    }
}
