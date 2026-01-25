<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantUserRequest;
use App\Http\Requests\UpdateTenantUserRequest;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TenantUserAdminController extends Controller
{
    private const LAST_ADMIN_MESSAGE = 'Cannot disable or change the role of the last enabled tenant admin.';

    /**
     * List users for the current tenant.
     * GET /api/tenant/users
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $users = User::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'tenant_id', 'name', 'email', 'role', 'is_enabled', 'created_at']);

        return response()->json($users->map(fn ($u) => [
            'id' => $u->id,
            'tenant_id' => $u->tenant_id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'is_enabled' => $u->is_enabled,
            'created_at' => $u->created_at->toIso8601String(),
        ]));
    }

    /**
     * Create a user in the current tenant.
     * POST /api/tenant/users
     */
    public function store(StoreTenantUserRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $v = $request->validated();
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $v['name'],
            'email' => $v['email'],
            'password' => Hash::make($v['password']),
            'role' => $v['role'],
            'is_enabled' => true,
        ]);

        return response()->json([
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_enabled' => $user->is_enabled,
            'created_at' => $user->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Update a user (role, is_enabled, name, email). Soft-disable via is_enabled.
     * PUT /api/tenant/users/{id}
     */
    public function update(UpdateTenantUserRequest $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $user = User::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $data = $request->validated();

        $wouldDisable = isset($data['is_enabled']) && $data['is_enabled'] === false;
        $wouldDemote = isset($data['role']) && $data['role'] !== 'tenant_admin';

        if (($wouldDisable || $wouldDemote) && $user->role === 'tenant_admin') {
            $count = User::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->where('is_enabled', true)
                ->count();
            if ($count <= 1) {
                return response()->json(['message' => self::LAST_ADMIN_MESSAGE], 422);
            }
        }

        $user->update($data);

        return response()->json([
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_enabled' => $user->is_enabled,
            'created_at' => $user->created_at->toIso8601String(),
        ]);
    }

    /**
     * Soft-disable a user (set is_enabled=false). Does not hard-delete.
     * DELETE /api/tenant/users/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $user = User::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

        if ($user->role === 'tenant_admin') {
            $count = User::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->where('is_enabled', true)
                ->count();
            if ($count <= 1) {
                return response()->json(['message' => self::LAST_ADMIN_MESSAGE], 422);
            }
        }

        $user->update(['is_enabled' => false]);

        return response()->json(null, 204);
    }
}
