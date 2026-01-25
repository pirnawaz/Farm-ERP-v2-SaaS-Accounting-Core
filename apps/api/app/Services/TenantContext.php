<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantContext
{
    private static ?Tenant $currentTenant = null;

    /**
     * Get the current tenant from the request.
     * This should be called after ResolveTenant middleware has run.
     */
    public static function getTenant(Request $request): ?Tenant
    {
        if (self::$currentTenant !== null) {
            return self::$currentTenant;
        }

        $tenantId = $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return null;
        }

        self::$currentTenant = Tenant::find($tenantId);
        return self::$currentTenant;
    }

    /**
     * Get the current tenant ID from the request.
     */
    public static function getTenantId(Request $request): ?string
    {
        return $request->attributes->get('tenant_id');
    }

    /**
     * Clear the cached tenant (useful for testing).
     */
    public static function clear(): void
    {
        self::$currentTenant = null;
    }
}
