<?php

namespace App\Http\Middleware;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\User;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantAuth
{
    /**
     * For tenant-scoped routes: resolve identity from auth cookie.
     * Validates cookie tenant_id matches resolved tenant; rejects cross-tenant.
     * Platform admin cookie (tenant_id null) does not get identity on tenant routes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/platform/*')) {
            return $next($request);
        }
        if ($request->is('api/dev/*')) {
            return $next($request);
        }
        if ($request->is('api/auth/set-password-with-token') || $request->is('api/auth/accept-invite')) {
            return $next($request);
        }
        if ($request->is('api/health')) {
            return $next($request);
        }

        $tenantId = $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return $next($request);
        }

        $token = $request->cookie(AuthCookie::NAME);
        // In testing, also accept the same token via Authorization so tests can forward cookie value
        if (!$token && app()->environment('testing')) {
            $token = $request->bearerToken();
        }
        if (!$token) {
            return $next($request);
        }

        $data = AuthToken::parse($token);
        if (!$data) {
            return $next($request);
        }

        $tokenTenantId = $data['tenant_id'] ?? null;
        if ($tokenTenantId === null) {
            return $next($request);
        }
        if ($tokenTenantId !== $tenantId) {
            return response()->json(['error' => 'Cross-tenant access denied'], 403);
        }

        $user = User::find($data['user_id'] ?? null);
        if (!$user || !$user->is_enabled) {
            return $next($request);
        }
        $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        if ($user->token_version !== $version) {
            return $next($request);
        }
        $tenant = Tenant::find($tenantId);
        if (!$tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
            return $next($request);
        }

        $request->attributes->set('user_id', $data['user_id'] ?? '');
        $request->attributes->set('user_role', $data['role'] ?? '');
        if (!empty($data['impersonator_user_id'])) {
            $request->attributes->set('impersonator_user_id', $data['impersonator_user_id']);
        }

        return $next($request);
    }
}
