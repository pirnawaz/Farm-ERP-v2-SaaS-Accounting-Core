<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip tenant check for health endpoint
        if ($request->is('api/health')) {
            return $next($request);
        }
        
        // Skip tenant check for dev endpoints
        if ($request->is('api/dev/*')) {
            return $next($request);
        }

        // Skip tenant check for platform admin endpoints
        if ($request->is('api/platform/*')) {
            return $next($request);
        }
        
        $tenantId = $request->header('X-Tenant-Id');
        
        if (!$tenantId) {
            return response()->json([
                'error' => 'X-Tenant-Id header is required'
            ], 400);
        }
        
        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
            return response()->json([
                'error' => 'X-Tenant-Id must be a valid UUID'
            ], 400);
        }
        
        // Verify tenant exists and is active
        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found'
            ], 404);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                'error' => 'Tenant is not active'
            ], 403);
        }
        
        // Attach tenant_id to request attributes for use in controllers
        $request->attributes->set('tenant_id', $tenantId);
        
        return $next($request);
    }
}
