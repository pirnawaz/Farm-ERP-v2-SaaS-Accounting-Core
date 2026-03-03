<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\TenantAddonModule;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Add-on expansion modules (orchards, livestock) per tenant.
 * Response is stable: known keys always present; no row or is_enabled=false => false.
 */
class TenantAddonModulesController extends Controller
{
    /** Known addon module keys; response always includes these. */
    public const KNOWN_KEYS = ['orchards', 'livestock'];

    /**
     * GET /api/tenant/addon-modules
     * Returns which addon modules are enabled for the current tenant.
     * Auth: any tenant-authenticated user (tenant_admin, accountant, operator).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $modules = $this->buildModulesResponse($tenantId);
        return response()->json(['modules' => $modules]);
    }

    /**
     * PATCH /api/tenant/addon-modules/{module_key}
     * Enable or disable an addon module for the current tenant. tenant_admin only.
     * Body: { "is_enabled": true|false }
     */
    public function update(Request $request, string $moduleKey): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (!in_array($moduleKey, self::KNOWN_KEYS, true)) {
            return response()->json(['error' => 'Unknown addon module'], 404);
        }

        $validator = Validator::make($request->all(), [
            'is_enabled' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $isEnabled = (bool) $request->input('is_enabled');

        $row = TenantAddonModule::forTenant($tenantId)
            ->where('module_key', $moduleKey)
            ->first();

        if ($row) {
            $row->update([
                'is_enabled' => $isEnabled,
                'enabled_at' => $isEnabled ? now() : $row->enabled_at,
                'disabled_at' => $isEnabled ? null : now(),
            ]);
        } else {
            if ($isEnabled) {
                TenantAddonModule::create([
                    'tenant_id' => $tenantId,
                    'module_key' => $moduleKey,
                    'is_enabled' => true,
                    'enabled_at' => now(),
                ]);
            }
            // If disabling and no row: treat as already disabled; no row created
        }

        $modules = $this->buildModulesResponse($tenantId);
        return response()->json(['modules' => $modules]);
    }

    private function buildModulesResponse(string $tenantId): array
    {
        $rows = TenantAddonModule::forTenant($tenantId)
            ->whereIn('module_key', self::KNOWN_KEYS)
            ->get()
            ->keyBy('module_key');

        $modules = [];
        foreach (self::KNOWN_KEYS as $key) {
            $row = $rows->get($key);
            $modules[$key] = $row ? $row->is_enabled : false;
        }
        return $modules;
    }
}
