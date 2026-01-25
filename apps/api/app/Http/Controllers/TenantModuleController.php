<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTenantModulesRequest;
use App\Models\Module;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantModuleController extends Controller
{
    /**
     * List all modules with enabled/status for the current tenant.
     * GET /api/tenant/modules
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $modules = Module::orderBy('sort_order')->orderBy('name')->get();
        $pivots = TenantModule::where('tenant_id', $tenantId)
            ->get()
            ->keyBy('module_id');

        $items = $modules->map(function (Module $m) use ($pivots) {
            $pivot = $pivots->get($m->id);
            if ($pivot) {
                $enabled = $pivot->status === 'ENABLED';
                $status = $pivot->status;
            } else {
                $enabled = $m->is_core;
                $status = $m->is_core ? 'ENABLED' : 'DISABLED';
            }
            return [
                'key' => $m->key,
                'name' => $m->name,
                'description' => $m->description,
                'is_core' => $m->is_core,
                'sort_order' => $m->sort_order,
                'enabled' => $enabled,
                'status' => $status,
            ];
        });

        return response()->json(['modules' => $items->values()]);
    }

    /**
     * Update enabled/disabled state of modules for the current tenant.
     * PUT /api/tenant/modules
     */
    public function update(UpdateTenantModulesRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $userId = $request->attributes->get('user_id') ?? $request->header('X-User-Id');

        foreach ($request->validated('modules') as $item) {
            $key = $item['key'];
            $enabled = $item['enabled'];

            $module = Module::where('key', $key)->firstOrFail();

            if ($module->is_core && $enabled === false) {
                return response()->json([
                    'message' => 'Core modules cannot be disabled.',
                ], 422);
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

            if ($enabled) {
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

        return $this->index($request);
    }
}
