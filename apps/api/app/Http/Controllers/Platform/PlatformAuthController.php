<?php

namespace App\Http\Controllers\Platform;

use App\Helpers\AuthCookie;
use App\Helpers\AuthToken;
use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\IdentityAuditLog;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PlatformAuthController extends Controller
{
    /**
     * Platform login: validate email+password without tenant, require platform_admin role.
     * Sets same auth cookie as tenant login; tenant_id in token is null for platform context.
     * POST /api/platform/auth/login
     * Body: { email, password }
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);
        $email = strtolower(trim((string) $validated['email']));

        // Prefer platform user (tenant_id null) so same email as tenant user still finds platform admin
        $user = User::whereNull('tenant_id')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if (!$user || !$user->password || !Hash::check($validated['password'], $user->password)) {
            // If no platform user or wrong password, check for tenant user with same email → 403
            $tenantUser = User::whereNotNull('tenant_id')
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->first();
            if ($tenantUser && $tenantUser->password && Hash::check($validated['password'], $tenantUser->password)) {
                IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGIN_FAILURE, null, null, ['reason' => 'not_platform_admin', 'email' => $email], $request);
                return response()->json(['error' => 'Access denied. Platform admin role required.'], 403);
            }
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGIN_FAILURE, null, null, ['reason' => 'invalid_credentials', 'email' => $email], $request);
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if ($user->role !== 'platform_admin') {
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGIN_FAILURE, null, null, ['reason' => 'not_platform_admin', 'email' => $email], $request);
            return response()->json(['error' => 'Access denied. Platform admin role required.'], 403);
        }

        if (!$user->is_enabled) {
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGIN_FAILURE, null, $user->id, ['reason' => 'user_disabled', 'email' => $email], $request);
            return response()->json(['error' => 'User is disabled'], 403);
        }

        IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGIN_SUCCESS, null, $user->id, ['email' => strtolower(trim($user->email ?? ''))], $request);

        $token = AuthToken::create($user, null);
        $cookie = AuthCookie::make($token);
        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => null,
        ])->cookie($cookie);
    }

    private const IMPERSONATION_COOKIE_NAME = 'farm_erp_impersonation';

    /**
     * Platform logout: clear auth cookie.
     * POST /api/platform/auth/logout
     * Returns 409 if currently impersonating (must stop impersonation first).
     */
    public function logout(Request $request): JsonResponse
    {
        if ($this->isImpersonating($request)) {
            return response()->json([
                'message' => 'Stop impersonation before platform logout.',
                'error' => 'logout_while_impersonating',
            ], 409);
        }
        return response()->json(['message' => 'Logged out successfully'])
            ->cookie(AuthCookie::make('', true));
    }

    /**
     * Platform logout everywhere: increment token_version, clear cookie.
     * POST /api/platform/auth/logout-all
     * Returns 409 if currently impersonating (must stop impersonation first).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        if ($this->isImpersonating($request)) {
            return response()->json([
                'message' => 'Stop impersonation before platform logout.',
                'error' => 'logout_while_impersonating',
            ], 409);
        }
        $token = $request->cookie(AuthCookie::NAME);
        if (app()->environment('testing')) {
            $token = $token ?: $request->bearerToken();
        }
        $userId = null;
        if ($token) {
            $data = AuthToken::parse($token);
            if ($data && !empty($data['user_id'])) {
                $userId = $data['user_id'];
                User::where('id', $userId)->increment('token_version');
            }
        }
        if ($userId) {
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_PLATFORM_LOGOUT_ALL, null, $userId, [], $request);
        }
        return response()->json(['message' => 'Logged out from all devices'])
            ->cookie(AuthCookie::make('', true));
    }

    private function isImpersonating(Request $request): bool
    {
        $value = $request->cookie(self::IMPERSONATION_COOKIE_NAME);
        if (!$value) {
            return false;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) && !empty($decoded['target_tenant_id']);
    }

    /**
     * Platform change password. Updates password, last_password_change_at, increments token_version.
     * POST /api/platform/auth/change-password
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
        if (!$data || empty($data['user_id']) || ($data['tenant_id'] ?? null) !== null) {
            return response()->json(['error' => 'Platform admin session required'], 401);
        }

        $user = User::find($data['user_id']);
        if (!$user || !$user->is_enabled || $user->tenant_id !== null) {
            return response()->json(['error' => 'User not found or not a platform admin'], 403);
        }
        if (!Hash::check($validated['current_password'], $user->password ?? '')) {
            return response()->json(['error' => 'Current password is incorrect'], 422);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
            'last_password_change_at' => now(),
            'token_version' => $user->token_version + 1,
        ]);

        $newToken = AuthToken::create($user, null);
        return response()->json(['message' => 'Password changed. You have been signed in with a new session.'])
            ->cookie(AuthCookie::make($newToken));
    }

    /**
     * Current platform user from cookie (or from request attributes when already resolved by middleware).
     * GET /api/platform/auth/me
     * Supports both legacy User and Identity-based auth.
     */
    public function me(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('identity');
        if ($identity) {
            return response()->json([
                'user' => [
                    'id' => $identity->id,
                    'name' => $identity->email,
                    'email' => $identity->email,
                    'role' => 'platform_admin',
                ],
                'tenant' => null,
            ]);
        }

        $userId = $request->attributes->get('user_id');
        if ($userId) {
            $user = User::find($userId);
            if ($user && $user->is_enabled && $user->role === 'platform_admin') {
                return response()->json([
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                    ],
                    'tenant' => null,
                ]);
            }
        }

        $token = $request->cookie(AuthCookie::NAME);
        if (!$token && app()->environment('testing')) {
            $token = $request->bearerToken();
        }
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $data = AuthToken::parse($token);
        if (!$data) {
            return response()->json(['error' => 'Token expired or invalid'], 401);
        }
        if (!in_array($data['role'] ?? '', ['platform_admin'], true)) {
            return response()->json(['error' => 'Not a platform admin'], 403);
        }

        $user = User::find($data['user_id'] ?? null);
        if (!$user || !$user->is_enabled) {
            return response()->json(['error' => 'User not found or disabled'], 403);
        }
        $version = (int) ($data['v'] ?? $data['token_version'] ?? 1);
        if ($user->token_version !== $version) {
            return response()->json(['error' => 'Session invalidated. Please log in again.'], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => null,
        ]);
    }
}
