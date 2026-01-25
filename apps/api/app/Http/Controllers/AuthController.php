<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Login: validate email+password, return user_id, role, tenant_id.
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

        return response()->json([
            'user_id' => $user->id,
            'role' => $user->role,
            'tenant_id' => $user->tenant_id,
        ]);
    }
}
