<?php

namespace App\Http\Controllers;

use App\Domains\Tenant\User\TenantUserManagementService;
use App\Models\User;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $users = User::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    public function store(StoreUserRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
        ]);

        return response()->json($user, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $user = User::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json($user);
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $user = User::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $user->update($request->validated());

        return response()->json($user);
    }

    /**
     * Legacy resource route: align with DELETE /api/tenant/users/{id} — soft-remove (disable), do not hard-delete.
     * Preserves the users row, identity links, and audit history.
     */
    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $tenant = TenantContext::getTenant($request);
        if (! $tenantId || ! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $user = User::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($user->role === 'tenant_admin') {
            $count = User::where('tenant_id', $tenantId)
                ->where('role', 'tenant_admin')
                ->where('is_enabled', true)
                ->count();
            if ($count <= 1) {
                return response()->json([
                    'message' => 'Cannot disable or change the role of the last enabled tenant admin',
                ], 422);
            }
        }

        app(TenantUserManagementService::class)->removeFromTenant($user, $tenant);

        return response()->json(null, 204);
    }
}
