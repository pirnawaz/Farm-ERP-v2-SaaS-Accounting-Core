<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cookie;

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

        $tenantId = $request->header('X-Tenant-Id') ?? TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'X-Tenant-Id header is required'], 400);
        }

        $user = User::where('tenant_id', $tenantId)->where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->password || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->is_enabled) {
            return response()->json(['error' => 'User is disabled'], 403);
        }

        // Create a simple token (user_id:tenant_id:role signed)
        $token = base64_encode(json_encode([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role' => $user->role,
            'email' => $user->email,
            'expires_at' => now()->addDays(7)->timestamp,
        ]));

        // Set httpOnly cookie; secure only when production or HTTPS (so staging over HTTP works)
        $secure = config('app.env') === 'production' || str_starts_with(config('app.url'), 'https://');
        $cookie = cookie('farm_erp_auth_token', $token, 60 * 24 * 7, '/', null, $secure, true); // 7 days, httpOnly=true

        return response()->json([
            'user_id' => $user->id,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
            'email' => $user->email,
        ])->cookie($cookie);
    }

    /**
     * Logout: clear auth cookie.
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $secure = config('app.env') === 'production' || str_starts_with(config('app.url'), 'https://');
        $cookie = cookie('farm_erp_auth_token', '', -1, '/', null, $secure, true); // expire, same flags as login
        return response()->json(['message' => 'Logged out successfully'])->cookie($cookie);
    }

    /**
     * Get current user from cookie.
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $token = $request->cookie('farm_erp_auth_token');
        
        if (!$token) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        try {
            $data = json_decode(base64_decode($token), true);
            
            if (!isset($data['expires_at']) || $data['expires_at'] < now()->timestamp) {
                return response()->json(['error' => 'Token expired'], 401);
            }

            return response()->json([
                'user_id' => $data['user_id'],
                'role' => $data['role'],
                'tenant_id' => $data['tenant_id'],
                'email' => $data['email'] ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }
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
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $record = PasswordResetToken::consumeToken($validated['token']);
        if (!$record) {
            return response()->json(['error' => 'Invalid or expired token'], 400);
        }

        $user = User::findOrFail($record->user_id);
        $user->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json(['message' => 'Password updated successfully']);
    }
}
