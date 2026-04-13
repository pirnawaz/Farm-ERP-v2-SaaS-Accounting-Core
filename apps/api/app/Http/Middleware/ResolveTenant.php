<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        protected TenantResolver $resolver
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        \App\Services\TenantContext::clear();

        if ($request->is('api/health')) {
            return $next($request);
        }
        if ($request->is('api/dev/*')) {
            return $next($request);
        }
        if ($request->is('api/platform/*')) {
            return $next($request);
        }
        if ($request->is('api/auth/set-password-with-token') || $request->is('api/auth/accept-invite')) {
            return $next($request);
        }
        // Unified login and select-tenant: no tenant required unless client sent identifier (legacy or explicit tenant).
        // Do not use auth-cookie tenant fallback here — stale cookies would force legacy login and block platform admins.
        if ($request->is('api/auth/login') || $request->is('api/auth/select-tenant')) {
            $tenant = $this->resolver->resolve($request, false);
            if (!$tenant && $this->hasTenantIdentifier($request)) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }
            if ($tenant) {
                $request->attributes->set('tenant_id', $tenant->id);
            }
            return $next($request);
        }

        $tenant = $this->resolver->resolve($request);

        if (!$tenant) {
            $hasIdentifier = $request->header('X-Tenant-Id') || $request->header('X-Tenant-Slug') || $this->hasSubdomainSlug($request);
            if ($hasIdentifier) {
                return response()->json(['error' => 'Tenant not found'], 404);
            }
            return response()->json(['error' => 'Tenant identifier required. Send X-Tenant-Id or X-Tenant-Slug.'], 422);
        }

        $request->attributes->set('tenant_id', $tenant->id);
        return $next($request);
    }

    private function hasTenantIdentifier(Request $request): bool
    {
        return $request->header('X-Tenant-Id') !== null
            || $request->header('X-Tenant-Slug') !== null
            || $this->hasSubdomainSlug($request);
    }

    private function hasSubdomainSlug(Request $request): bool
    {
        $host = $request->header('Host');
        $rootDomain = config('app.root_domain');
        if (!$host || !$rootDomain || !is_string($rootDomain) || trim($rootDomain) === '') {
            return false;
        }
        $suffix = '.' . strtolower(trim($rootDomain));
        $host = strtolower(trim($host));
        return $host !== $rootDomain && str_ends_with($host, $suffix) && substr($host, 0, -strlen($suffix)) !== '';
    }
}
