<?php

namespace App\Http\Middleware;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantAuth
{
    /**
     * For tenant-scoped routes: resolve identity from auth cookie.
     * Validates cookie tenant_id matches resolved tenant; rejects cross-tenant.
     * Supports both legacy (user_id) and identity-based (identity_id) tokens.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/platform/*')) {
            return $next($request);
        }
        if ($request->is('api/dev/*')) {
            return $next($request);
        }
        if ($request->is('api/auth/set-password-with-token') || $request->is('api/auth/accept-invite') || $request->is('api/auth/login') || $request->is('api/auth/select-tenant')) {
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

        $tokenTenantId = $data['tenant_id'] ?? $data['active_tenant_id'] ?? null;
        if ($tokenTenantId === null) {
            return $next($request);
        }
        if ($tokenTenantId !== $tenantId) {
            return response()->json(['error' => 'Cross-tenant access denied'], 403);
        }

        if (!empty($data['identity_id'])) {
            return $this->handleIdentityToken($request, $next, $data, $tenantId);
        }

        return $this->handleLegacyToken($request, $next, $data, $tenantId);
    }

    private function handleIdentityToken(Request $request, Closure $next, array $data, string $tenantId): Response
    {
        $identity = Identity::find($data['identity_id'] ?? null);
        if (!$identity || !$identity->is_enabled) {
            return $next($request);
        }
        $version = (int) ($data['v'] ?? 1);
        if ($identity->token_version !== $version) {
            return $next($request);
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
            return $next($request);
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->first();
        if (!$membership) {
            return response()->json(['error' => 'No access to this farm'], 403);
        }

        $userId = $data['user_id'] ?? null;
        if (!$userId) {
            $user = User::where('identity_id', $identity->id)->where('tenant_id', $tenantId)->first();
            $userId = $user?->id ?? $identity->id;
        }
        $request->attributes->set('user_id', $userId);
        $request->attributes->set('user_role', $membership->role);
        $request->attributes->set('identity_id', $identity->id);
        if (!empty($data['impersonator_user_id'])) {
            $request->attributes->set('impersonator_user_id', $data['impersonator_user_id']);
        }

        return $next($request);
    }

    private function handleLegacyToken(Request $request, Closure $next, array $data, string $tenantId): Response
    {
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
