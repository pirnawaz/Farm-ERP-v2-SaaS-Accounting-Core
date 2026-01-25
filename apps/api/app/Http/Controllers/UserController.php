<?php

namespace App\Http\Controllers;

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

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $user = User::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $user->delete();

        return response()->json(null, 204);
    }
}
