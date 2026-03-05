<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvitationRequest;
use App\Models\IdentityAuditLog;
use App\Models\User;
use App\Services\IdentityAuditLogger;
use App\Services\InvitationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class TenantInvitationController extends Controller
{
    /** Role order: higher index = lower privilege. Inviter can only invite same or lower. */
    private const ROLE_ORDER = ['tenant_admin' => 0, 'accountant' => 1, 'operator' => 2];

    public function __construct(
        protected InvitationService $invitationService
    ) {}

    /**
     * Create an invitation (tenant_admin only). Returns invite link with token.
     * 409 if email already exists in tenant; 422 if email is a platform admin.
     * Re-invite: if not expired returns same link; if expired creates new.
     * POST /api/tenant/invitations
     */
    public function store(StoreInvitationRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $v = $request->validated();
        $email = $this->invitationService->normalizeEmail($v['email']);

        if (User::where('tenant_id', $tenantId)->where('email', $email)->exists()) {
            return response()->json(['error' => 'A user with this email already exists in this tenant'], 409);
        }

        if (User::whereNull('tenant_id')->where('email', $email)->exists()) {
            return response()->json(['error' => 'Cannot invite a platform admin email to a tenant'], 422);
        }

        $userId = $request->attributes->get('user_id');
        if (!$userId) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $inviter = User::find($userId);
        if ($inviter) {
            $inviterOrder = self::ROLE_ORDER[$inviter->role] ?? 99;
            $inviteeOrder = self::ROLE_ORDER[$v['role']] ?? 99;
            if ($inviteeOrder < $inviterOrder) {
                return response()->json(['error' => 'You can only invite users with the same or a lower role'], 422);
            }
        }

        $ttlHours = (int) config('app.invitation_ttl_hours', 168);
        $result = $this->invitationService->createInvitationForTenant(
            $tenantId,
            $email,
            $v['role'],
            $userId,
            $ttlHours
        );

        if ($result['is_new']) {
            IdentityAuditLogger::log(IdentityAuditLog::ACTION_INVITATION_CREATED, $tenantId, $userId, ['invite_email' => $result['email'], 'role' => $result['role']], $request);
        }

        return response()->json([
            'message' => $result['is_new'] ? 'Invitation created' : 'Invitation already sent; same link returned',
            'invite_link' => $result['invite_link'],
            'expires_in_hours' => $result['expires_in_hours'],
        ], $result['is_new'] ? 201 : 200);
    }
}
