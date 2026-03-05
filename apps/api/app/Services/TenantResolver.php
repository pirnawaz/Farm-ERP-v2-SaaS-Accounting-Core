<?php

namespace App\Services;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantResolver
{
    /**
     * Resolve tenant from request.
     * Order: X-Tenant-Id (UUID) → X-Tenant-Slug → Subdomain (if Host and root domain configured) → auth cookie tenant_id (e.g. impersonation).
     * Does not reject — returns null if missing/invalid; middleware handles 400/404.
     */
    public function resolve(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-Id');
        if ($tenantId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                return $tenant;
            }
        }

        $slug = $request->header('X-Tenant-Slug');
        if ($slug && is_string($slug) && trim($slug) !== '') {
            $tenant = Tenant::where('slug', trim($slug))->first();
            if ($tenant) {
                return $tenant;
            }
        }

        $host = $request->header('Host');
        $rootDomain = config('app.root_domain');
        if ($host && $rootDomain && is_string($rootDomain) && trim($rootDomain) !== '') {
            $tenant = $this->resolveBySubdomain($host, trim($rootDomain));
            if ($tenant) {
                return $tenant;
            }
        }

        // Fallback: tenant from auth cookie (e.g. after impersonation when frontend may not have sent X-Tenant-Id yet)
        $token = $request->cookie(AuthCookie::NAME);
        if ($token) {
            $data = AuthToken::parse($token);
            $cookieTenantId = $data['tenant_id'] ?? null;
            if ($cookieTenantId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $cookieTenantId)) {
                $tenant = Tenant::find($cookieTenantId);
                if ($tenant) {
                    return $tenant;
                }
            }
        }

        return null;
    }

    /**
     * Resolve tenant by subdomain: <slug>.<root-domain>
     */
    private function resolveBySubdomain(string $host, string $rootDomain): ?Tenant
    {
        $host = strtolower(trim($host));
        $rootDomain = strtolower(trim($rootDomain));
        $suffix = '.' . $rootDomain;
        if ($host === $rootDomain || !str_ends_with($host, $suffix)) {
            return null;
        }
        $subdomain = substr($host, 0, -strlen($suffix));
        if ($subdomain === '' || $subdomain === 'www' || str_contains($subdomain, '.')) {
            return null;
        }
        return Tenant::where('slug', $subdomain)->where('status', Tenant::STATUS_ACTIVE)->first();
    }
}
