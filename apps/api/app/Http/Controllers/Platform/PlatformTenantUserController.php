<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformTenantUserRequest;
use App\Http\Requests\UpdatePlatformTenantUserRequest;
use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformTenantUserController extends Controller
{
    public function __construct(
        protected InvitationService $invitationService
    ) {}

    /**
     * Create a user in a tenant (platform admin). Same behavior as tenant create-user; audit platform_user_created_manual.
     * POST /api/platform/tenants/{tenant}/users
     */
    public function store(StorePlatformTenantUserRequest $request, string $tenant): JsonResponse
    {
        $tenantModel = Tenant::find($tenant);
        if (!$tenantModel) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            return response()->json(['error' => 'X-User-Id is required'], 400);
        }

        $v = $request->validated();
        $email = $this->invitationService->normalizeEmail($v['email']);

        if (User::where('tenant_id', $tenantModel->id)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
            return response()->json(['error' => 'A user with this email already exists in this tenant'], 409);
        }

        $temporaryPassword = isset($v['temporary_password']) && $v['temporary_password'] !== ''
            ? $v['temporary_password']
            : Str::random(14);

        $user = User::create([
            'tenant_id' => $tenantModel->id,
            'name' => trim($v['name']),
            'email' => $email,
            'password' => Hash::make($temporaryPassword),
            'role' => $v['role'],
            'is_enabled' => true,
            'must_change_password' => true,
        ]);

        IdentityAuditLogger::log(
            IdentityAuditLog::ACTION_PLATFORM_USER_CREATED_MANUAL,
            $tenantModel->id,
            $actorUserId,
            ['target_user_id' => $user->id, 'email' => $user->email, 'role' => $user->role],
            $request
        );

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    /**
     * Update a tenant user (role and/or is_enabled). Platform admin only.
     * PATCH /api/platform/tenants/{tenant}/users/{user}
     * Cannot disable or demote the last enabled tenant_admin.
     */
    public function update(UpdatePlatformTenantUserRequest $request, string $tenant, string $user): JsonResponse
    {
        $tenantModel = Tenant::find($tenant);
        if (!$tenantModel) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $targetUser = User::where('tenant_id', $tenantModel->id)->where('id', $user)->first();
        if (!$targetUser) {
            return response()->json(['error' => 'User not found in this tenant'], 404);
        }

        $actorUserId = $request->header('X-User-Id');
        if (!$actorUserId) {
            return response()->json(['error' => 'X-User-Id is required'], 400);
        }

        $v = $request->validated();
        $lastEnabledAdmin = User::where('tenant_id', $tenantModel->id)
            ->where('role', 'tenant_admin')
            ->where('is_enabled', true)
            ->first();

        $isLastEnabledAdmin = $lastEnabledAdmin && $lastEnabledAdmin->id === $targetUser->id;

        if (isset($v['is_enabled']) && $v['is_enabled'] === false) {
            if ($isLastEnabledAdmin) {
                return response()->json([
                    'error' => 'Cannot remove the last tenant admin.',
                    'message' => 'Cannot disable the last enabled tenant admin. Add or enable another admin first.',
                ], 422);
            }
        }

        if (isset($v['role'])) {
            if ($v['role'] === 'platform_admin') {
                return response()->json(['error' => 'Cannot set role to platform_admin'], 422);
            }
            if ($isLastEnabledAdmin && $v['role'] !== 'tenant_admin') {
                return response()->json([
                    'error' => 'Cannot remove the last tenant admin.',
                    'message' => 'Cannot demote the last enabled tenant admin. Add or enable another admin first.',
                ], 422);
            }
        }

        $beforeRole = $targetUser->role;
        $beforeEnabled = $targetUser->is_enabled;

        if (array_key_exists('role', $v)) {
            $targetUser->role = $v['role'];
        }
        if (array_key_exists('is_enabled', $v)) {
            $targetUser->is_enabled = (bool) $v['is_enabled'];
        }
        $targetUser->save();

        if (array_key_exists('role', $v) && $v['role'] !== $beforeRole) {
            IdentityAuditLogger::log(
                IdentityAuditLog::ACTION_PLATFORM_USER_ROLE_CHANGED,
                $tenantModel->id,
                $actorUserId,
                [
                    'target_user_id' => $targetUser->id,
                    'before' => $beforeRole,
                    'after' => $targetUser->role,
                ],
                $request
            );
        }
        if (array_key_exists('is_enabled', $v) && (bool) $v['is_enabled'] !== $beforeEnabled) {
            IdentityAuditLogger::log(
                IdentityAuditLog::ACTION_PLATFORM_USER_ENABLED_CHANGED,
                $tenantModel->id,
                $actorUserId,
                [
                    'target_user_id' => $targetUser->id,
                    'before' => $beforeEnabled,
                    'after' => $targetUser->is_enabled,
                ],
                $request
            );
        }

        return response()->json([
            'id' => $targetUser->id,
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'role' => $targetUser->role,
            'is_enabled' => $targetUser->is_enabled,
            'created_at' => $targetUser->created_at->toIso8601String(),
        ]);
    }
}
