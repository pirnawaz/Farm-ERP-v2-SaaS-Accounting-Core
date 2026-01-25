<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireModule
{
    /**
     * If the given module is disabled for the current tenant, return 403.
     * Must run after ResolveTenant.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $moduleKey  e.g. land, treasury_payments, ar_sales, settlements, reports
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $tenant = TenantContext::getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        if (!$tenant->isModuleEnabled($moduleKey)) {
            return response()->json(['message' => "Module {$moduleKey} is not enabled for this tenant."], 403);
        }
        return $next($request);
    }
}
