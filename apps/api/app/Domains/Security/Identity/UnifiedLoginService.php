<?php

namespace App\Domains\Security\Identity;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Handles unified login (email + password) and returns platform | tenant | select_tenant.
 */
class UnifiedLoginService
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        $email = strtolower(trim((string) $validated['email']));
        $password = $validated['password'];

        $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        if (!$identity || !Hash::check($password, $identity->password_hash)) {
            IdentityAuditLogger::log(
                \App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE,
                null,
                null,
                ['reason' => 'invalid_credentials', 'email' => $email],
                $request
            );
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$identity->is_enabled) {
            IdentityAuditLogger::log(
                \App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE,
                null,
                $identity->id,
                ['reason' => 'identity_disabled', 'email' => $email],
                $request
            );
            return response()->json(['error' => 'Account is disabled'], 403);
        }

        $identity->update(['last_login_at' => now()]);

        $enabledMemberships = $identity->enabledMemberships()
            ->with('tenant')
            ->get()
            ->filter(fn (TenantMembership $m) => $m->tenant && $m->tenant->status === Tenant::STATUS_ACTIVE);

        $tenantList = $enabledMemberships->map(fn (TenantMembership $m) => [
            'id' => $m->tenant_id,
            'slug' => $m->tenant->slug ?? null,
            'name' => $m->tenant->name,
            'role' => $m->role,
        ])->values()->all();

        $identityPayload = [
            'id' => $identity->id,
            'email' => $identity->email,
        ];

        if ($identity->is_platform_admin) {
            IdentityAuditLogger::log(
                \App\Models\IdentityAuditLog::ACTION_PLATFORM_LOGIN_SUCCESS,
                null,
                null,
                ['email' => $identity->email, 'identity_id' => $identity->id],
                $request
            );
            $token = AuthToken::createForIdentity($identity, null, 'platform_admin');
            $cookie = AuthCookie::make($token);
            return response()->json([
                'mode' => 'platform',
                'identity' => $identityPayload,
                'token' => $token,
                'tenant' => null,
            ])->cookie($cookie);
        }

        if ($tenantList === []) {
            return response()->json(['error' => 'No farm access'], 403);
        }

        if (count($tenantList) === 1) {
            $tenant = $enabledMemberships->first()->tenant;
            $membership = $enabledMemberships->first();
            $user = User::where('identity_id', $identity->id)->where('tenant_id', $tenant->id)->first();
            $token = AuthToken::createForIdentity(
                $identity,
                $tenant->id,
                $membership->role,
                $user?->id
            );
            $cookie = AuthCookie::make($token);
            IdentityAuditLogger::log(
                \App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS,
                $tenant->id,
                $user?->id ?? $identity->id,
                ['email' => $identity->email],
                $request
            );
            return response()->json([
                'mode' => 'tenant',
                'identity' => $identityPayload,
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug ?? null,
                ],
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $membership->role,
                    'must_change_password' => (bool) $user->must_change_password,
                ] : $identityPayload + ['role' => $membership->role, 'must_change_password' => false],
                'token' => $token,
            ])->cookie($cookie);
        }

        $token = AuthToken::createForIdentity($identity, null, '');
        $cookie = AuthCookie::make($token);
        return response()->json([
            'mode' => 'select_tenant',
            'identity' => $identityPayload,
            'tenants' => $tenantList,
            'token' => $token,
        ])->cookie($cookie);
    }

    public function selectTenant(Request $request): JsonResponse
    {
        $validated = $request->validate(['tenant_id' => ['required', 'uuid']]);
        $tenantId = $validated['tenant_id'];

        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $data = AuthToken::parse($token);
        if (!$data || empty($data['identity_id'])) {
            return response()->json(['error' => 'Invalid session. Please log in again.'], 401);
        }

        $identity = Identity::find($data['identity_id']);
        if (!$identity || !$identity->is_enabled) {
            return response()->json(['error' => 'Session invalid'], 401);
        }
        $version = (int) ($data['v'] ?? 1);
        if ($identity->token_version !== $version) {
            return response()->json(['error' => 'Session invalidated. Please log in again.'], 401);
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->with('tenant')
            ->first();

        if (!$membership || !$membership->tenant || $membership->tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json(['error' => 'Farm not found or access denied'], 403);
        }

        $tenant = $membership->tenant;
        $user = User::where('identity_id', $identity->id)->where('tenant_id', $tenant->id)->first();

        $newToken = AuthToken::createForIdentity($identity, $tenant->id, $membership->role, $user?->id);
        $cookie = AuthCookie::make($newToken);

        return response()->json([
            'mode' => 'tenant',
            'identity' => ['id' => $identity->id, 'email' => $identity->email],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug ?? null,
            ],
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $membership->role,
                'must_change_password' => (bool) $user->must_change_password,
            ] : ['id' => $identity->id, 'email' => $identity->email, 'role' => $membership->role, 'must_change_password' => false],
        ])->cookie($cookie);
    }
}
