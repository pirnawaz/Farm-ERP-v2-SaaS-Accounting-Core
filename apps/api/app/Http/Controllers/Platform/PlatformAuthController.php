<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;

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

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !$user->password || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->is_enabled) {
            return response()->json(['error' => 'User is disabled'], 403);
        }

        $allowedRoles = ['platform_admin'];
        if (!in_array($user->role, $allowedRoles, true)) {
            return response()->json(['error' => 'Access denied. Platform admin role required.'], 403);
        }

        $token = base64_encode(json_encode([
            'user_id' => $user->id,
            'tenant_id' => null,
            'role' => $user->role,
            'email' => $user->email,
            'expires_at' => now()->addDays(7)->timestamp,
        ]));

        $secure = config('app.env') === 'production' || str_starts_with(config('app.url'), 'https://');
        $cookie = cookie('farm_erp_auth_token', $token, 60 * 24 * 7, '/', null, $secure, true);

        return response()->json([
            'user_id' => $user->id,
            'role' => $user->role,
            'tenant_id' => null,
            'email' => $user->email,
            'name' => $user->name,
            'is_platform_admin' => true,
        ])->cookie($cookie);
    }

    /**
     * Platform logout: clear auth cookie.
     * POST /api/platform/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $secure = config('app.env') === 'production' || str_starts_with(config('app.url'), 'https://');
        $cookie = cookie('farm_erp_auth_token', '', -1, '/', null, $secure, true);
        return response()->json(['message' => 'Logged out successfully'])->cookie($cookie);
    }

    /**
     * Current platform user from cookie. No tenant required.
     * GET /api/platform/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->cookie('farm_erp_auth_token');
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        try {
            $data = json_decode(base64_decode($token), true);
            if (!is_array($data) || !isset($data['expires_at']) || $data['expires_at'] < now()->timestamp) {
                return response()->json(['error' => 'Token expired'], 401);
            }
            if (!in_array($data['role'] ?? '', ['platform_admin'], true)) {
                return response()->json(['error' => 'Not a platform admin'], 403);
            }

            $user = User::find($data['user_id'] ?? null);
            if (!$user || !$user->is_enabled) {
                return response()->json(['error' => 'User not found or disabled'], 403);
            }

            return response()->json([
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => [$user->role],
                'is_platform_admin' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
    }
}
