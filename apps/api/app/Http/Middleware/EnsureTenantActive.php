<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantActive
{
    /**
     * Block access when tenant is not ACTIVE (e.g. suspended).
     * Must run after ResolveTenant so tenant_id is set.
     * Returns 403 with message containing "tenant suspended" for non-active tenants.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for routes that do not resolve tenant (same as ResolveTenant)
        if ($request->is('api/health') || $request->is('api/dev/*') || $request->is('api/platform/*')) {
            return $next($request);
        }

        $tenant = TenantContext::getTenant($request);
        if (!$tenant) {
            return $next($request);
        }

        if ($tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json([
                'error' => 'Tenant suspended. Access is not allowed.',
            ], 403);
        }

        return $next($request);
    }
}
