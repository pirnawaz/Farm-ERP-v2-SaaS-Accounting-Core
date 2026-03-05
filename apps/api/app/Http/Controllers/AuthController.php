<?php

namespace App\Http\Controllers;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Login: validate email+password, set httpOnly cookie with auth token, return user info.
     * Reject with 403 if user is disabled.
     * POST /api/auth/login
     * Body: { email, password }
     * Header: X-Tenant-Id (required)
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        $email = strtolower(trim((string) $validated['email']));

        $tenantId = $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant identifier required'], 422);
        }

        $user = User::where('tenant_id', $tenantId)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        if (!$user) {
            $platformUser = User::whereNull('tenant_id')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($platformUser) {
                IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE, $tenantId, null, ['reason' => 'use_platform_login', 'email' => $email], $request);
                return response()->json(['error' => 'Use platform admin login for this account'], 403);
            }
            IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE, $tenantId, null, ['reason' => 'invalid_credentials', 'email' => $email], $request);
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->password || !Hash::check($validated['password'], $user->password)) {
            IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE, $tenantId, null, ['reason' => 'invalid_credentials', 'email' => $email], $request);
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if ($user->role === 'platform_admin' || $user->tenant_id === null) {
            IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE, $tenantId, null, ['reason' => 'use_platform_login', 'email' => $email], $request);
            return response()->json(['error' => 'Use platform admin login for this account'], 403);
        }

        if (!$user->is_enabled) {
            IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE, $tenantId, $user->id, ['reason' => 'user_disabled', 'email' => $email], $request);
            return response()->json(['error' => 'User is disabled'], 403);
        }

        $token = AuthToken::create($user, $user->tenant_id);
        $cookie = AuthCookie::make($token);
        $tenant = Tenant::find($user->tenant_id);

        IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS, $tenantId, $user->id, ['email' => strtolower(trim($user->email ?? ''))], $request);

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug ?? null,
            ] : null,
        ])->cookie($cookie);
    }

    /**
     * Logout: clear auth cookie.
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Logged out successfully'])
            ->cookie(AuthCookie::make('', true));
    }

    /**
     * Logout everywhere: increment token_version (invalidates all existing tokens), then clear cookie.
     * POST /api/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        $tenantId = $request->attributes->get('tenant_id');
        $userId = null;
        if ($token) {
            $data = AuthToken::parse($token);
            if ($data && !empty($data['user_id'])) {
                $userId = $data['user_id'];
                User::where('id', $userId)->increment('token_version');
            }
        }
        if ($userId && $tenantId) {
            IdentityAuditLogger::log(\App\Models\IdentityAuditLog::ACTION_TENANT_LOGOUT_ALL, $tenantId, $userId, [], $request);
        }
        return response()->json(['message' => 'Logged out from all devices'])
            ->cookie(AuthCookie::make('', true));
    }

    /**
     * Change password (tenant user). Updates password, last_password_change_at, increments token_version.
     * POST /api/auth/change-password
     * Body: { current_password, new_password }
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $data = AuthToken::parse($token);
        if (!$data || empty($data['user_id']) || ($data['tenant_id'] ?? null) === null) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $user = User::find($data['user_id']);
        if (!$user || !$user->is_enabled || $user->tenant_id === null) {
            return response()->json(['error' => 'User not found or disabled'], 403);
        }
        if (!Hash::check($validated['current_password'], $user->password ?? '')) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
            'last_password_change_at' => now(),
            'token_version' => $user->token_version + 1,
        ]);

        $newToken = AuthToken::create($user, $user->tenant_id);
        return response()->json(['message' => 'Password changed. You have been signed in with a new session.'])
            ->cookie(AuthCookie::make($newToken));
    }

    /**
     * Get current user from cookie.
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $data = AuthToken::parse($token);
        if (!$data) {
            return response()->json(['error' => 'Token expired or invalid'], 401);
        }

        if (!empty($data['identity_id'])) {
            $identity = Identity::find($data['identity_id']);
            if (!$identity || !$identity->is_enabled) {
                return response()->json(['error' => 'User not found or disabled'], 403);
            }
            $version = (int) ($data['v'] ?? 1);
            if ($identity->token_version !== $version) {
                return response()->json(['error' => 'Session invalidated. Please log in again.'], 401);
            }
            $tenantId = $data['tenant_id'] ?? $data['active_tenant_id'] ?? null;
            $tenant = $tenantId ? Tenant::find($tenantId) : null;
            if ($tenant && $tenant->status !== Tenant::STATUS_ACTIVE) {
                return response()->json(['error' => 'Tenant suspended'], 403);
            }
            $role = $data['role'] ?? null;
            if ($tenant) {
                $membership = TenantMembership::where('identity_id', $identity->id)->where('tenant_id', $tenant->id)->where('is_enabled', true)->first();
                $role = $membership?->role ?? $role;
            }
            $user = $data['user_id'] ? User::find($data['user_id']) : null;
            if (!$user && $tenant) {
                $user = User::where('identity_id', $identity->id)->where('tenant_id', $tenant->id)->first();
            }
            return response()->json([
                'user' => [
                    'id' => $user?->id ?? $identity->id,
                    'name' => $user?->name ?? $identity->email,
                    'email' => $identity->email,
                    'role' => $role ?? '',
                    'must_change_password' => (bool) ($user?->must_change_password ?? false),
                ],
                'tenant' => $tenant ? [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug ?? null,
                ] : null,
            ]);
        }

        $user = User::find($data['user_id'] ?? null);
        if (!$user || !$user->is_enabled) {
            return response()->json(['error' => 'User not found or disabled'], 403);
        }
        $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        if ($user->token_version !== $version) {
            return response()->json(['error' => 'Session invalidated. Please log in again.'], 401);
        }
        $tenant = $user->tenant_id ? Tenant::find($user->tenant_id) : null;
        if ($tenant && $tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json(['error' => 'Tenant suspended'], 403);
        }
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'must_change_password' => (bool) $user->must_change_password,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug ?? null,
            ] : null,
        ]);
    }

    /**
     * Diagnostic: current request identity from auth token. Tenant-scoped.
     * GET /api/auth/whoami
     * Returns user_id, user_role, tenant_id, impersonator_user_id (when impersonating).
     * Reads token from cookie or (in testing) Bearer so it works regardless of middleware attribute order.
     */
    public function whoami(Request $request): JsonResponse
    {
        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $data = AuthToken::parse($token);
        if (!$data) {
            return response()->json(['error' => 'Token expired or invalid'], 401);
        }
        $userId = $data['user_id'] ?? null;
        $tenantId = $data['tenant_id'] ?? null;
        if (!$userId || !$tenantId) {
            return response()->json(['error' => 'Not authenticated as tenant user'], 401);
        }
        $user = User::find($userId);
        if (!$user || !$user->is_enabled) {
            return response()->json(['error' => 'User not found or disabled'], 403);
        }
        $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        if ($user->token_version !== $version) {
            return response()->json(['error' => 'Session invalidated'], 401);
        }
        $tenant = Tenant::find($tenantId);
        if (!$tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
            return response()->json(['error' => 'Tenant not found or inactive'], 403);
        }
        return response()->json([
            'user_id' => $userId,
            'user_role' => $data['role'] ?? $user->role,
            'tenant_id' => $tenantId,
            'impersonator_user_id' => $data['impersonator_user_id'] ?? null,
        ]);
    }

    /**
     * Complete first-login password update (user with must_change_password=true).
     * POST /api/auth/complete-first-login-password
     * Body: { new_password, new_password_confirmation }
     */
    public function completeFirstLoginPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'new_password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }
        $data = AuthToken::parse($token);
        if (!$data || empty($data['user_id']) || ($data['tenant_id'] ?? null) === null) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $user = User::find($data['user_id']);
        if (!$user || !$user->is_enabled || $user->tenant_id === null) {
            return response()->json(['error' => 'User not found or disabled'], 403);
        }
        if (!$user->must_change_password) {
            return response()->json(['error' => 'Password was already set'], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
            'must_change_password' => false,
            'last_password_change_at' => now(),
            'token_version' => $user->token_version + 1,
        ]);

        IdentityAuditLogger::log(
            \App\Models\IdentityAuditLog::ACTION_FIRST_LOGIN_PASSWORD_SET,
            $user->tenant_id,
            $user->id,
            ['user_id' => $user->id],
            $request
        );

        $newToken = AuthToken::create($user, $user->tenant_id);
        return response()->json(['message' => 'Password set. You can now use the app.'])
            ->cookie(AuthCookie::make($newToken));
    }

    /**
     * Set password using a one-time reset token (from platform admin reset).
     * POST /api/auth/set-password-with-token
     * Body: { token, new_password }
     */
    public function setPasswordWithToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'new_password' => ['required', 'string', Password::defaults()],
        ]);

        $record = PasswordResetToken::consumeToken($validated['token']);
        if (!$record) {
            return response()->json(['error' => 'Invalid or expired token'], 400);
        }

        $user = User::findOrFail($record->user_id);
        $passwordHash = Hash::make($validated['new_password']);
        $user->update([
            'password' => $passwordHash,
            'last_password_change_at' => now(),
            'token_version' => $user->token_version + 1,
        ]);

        // Update Identity so unified login works with the new password.
        $identity = $user->identity_id ? Identity::find($user->identity_id) : null;
        if (!$identity && $user->email) {
            $email = strtolower(trim((string) $user->email));
            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if (!$identity) {
                $identity = Identity::create([
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'is_enabled' => (bool) $user->is_enabled,
                    'is_platform_admin' => false,
                    'token_version' => 1,
                ]);
                $user->update(['identity_id' => $identity->id]);
            } else {
                $user->update(['identity_id' => $identity->id]);
            }
            $membership = TenantMembership::where('identity_id', $identity->id)
                ->where('tenant_id', $user->tenant_id)->first();
            if (!$membership) {
                TenantMembership::create([
                    'identity_id' => $identity->id,
                    'tenant_id' => $user->tenant_id,
                    'role' => $user->role,
                    'is_enabled' => (bool) $user->is_enabled,
                ]);
            }
        }
        if ($identity) {
            $identity->update([
                'password_hash' => $passwordHash,
                'token_version' => $identity->token_version + 1,
            ]);
        }

        return response()->json(['message' => 'Password updated successfully']);
    }
}
