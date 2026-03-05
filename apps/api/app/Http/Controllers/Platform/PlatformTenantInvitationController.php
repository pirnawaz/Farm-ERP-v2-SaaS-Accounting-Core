<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlatformInvitationRequest;
use App\Models\IdentityAuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;

class PlatformTenantInvitationController extends Controller
{
    private const ALLOWED_ROLES = ['tenant_admin', 'accountant', 'operator'];

    public function __construct(
        protected InvitationService $invitationService
    ) {}

    /**
     * Create an invitation for a tenant user. Platform admin only.
     * POST /api/platform/tenants/{tenant}/invitations
     * Body: email (required), role (optional). Default role: tenant_admin if 0 users, else operator.
     */
    public function store(StorePlatformInvitationRequest $request, string $tenant): JsonResponse
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

        if (User::where('tenant_id', $tenantModel->id)->where('email', $email)->exists()) {
            return response()->json(['error' => 'User already exists in this tenant'], 409);
        }

        if (User::whereNull('tenant_id')->where('email', $email)->exists()) {
            return response()->json(['error' => 'Cannot invite a platform admin email to a tenant'], 422);
        }

        $userCount = User::where('tenant_id', $tenantModel->id)->count();
        $role = $v['role'] ?? ($userCount === 0 ? 'tenant_admin' : 'operator');
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            return response()->json(['error' => 'Invalid role. Allowed: tenant_admin, accountant, operator'], 422);
        }

        $ttlHours = (int) config('app.invitation_ttl_hours', 168);
        $result = $this->invitationService->createInvitationForTenant(
            $tenantModel->id,
            $email,
            $role,
            $actorUserId,
            $ttlHours
        );

        IdentityAuditLogger::log(
            IdentityAuditLog::ACTION_PLATFORM_INVITATION_CREATED,
            $tenantModel->id,
            $actorUserId,
            [
                'tenant_id' => $tenantModel->id,
                'email' => $result['email'],
                'role' => $result['role'],
                'invitation_id' => $result['invitation_id'],
            ],
            $request
        );

        return response()->json([
            'invite_link' => $result['invite_link'],
            'expires_in_hours' => $result['expires_in_hours'],
            'email' => $result['email'],
            'role' => $result['role'],
        ], $result['is_new'] ? 201 : 200);
    }
}
