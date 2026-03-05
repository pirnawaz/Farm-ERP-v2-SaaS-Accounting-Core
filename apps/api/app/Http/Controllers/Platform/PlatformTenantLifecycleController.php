<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\PasswordResetToken;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformTenantLifecycleController extends Controller
{
    /**
     * Resolve or create Identity for the tenant admin user and ensure TenantMembership exists.
     * Links user.identity_id if missing. Returns the Identity or null if admin has no email.
     */
    private function resolveOrCreateIdentityForTenantAdmin(User $admin, Tenant $tenant): ?Identity
    {
        $email = $admin->email ? strtolower(trim((string) $admin->email)) : '';
        if ($email === '') {
            return null;
        }

        $identity = null;
        if ($admin->identity_id) {
            $identity = Identity::find($admin->identity_id);
        }
        if (!$identity) {
            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
        }
        if (!$identity) {
            $identity = Identity::create([
                'email' => $email,
                'password_hash' => $admin->password ?: Hash::make(Str::random(32)),
                'is_enabled' => (bool) $admin->is_enabled,
                'is_platform_admin' => false,
                'token_version' => 1,
            ]);
        }

        if ($admin->identity_id !== $identity->id) {
            $admin->update(['identity_id' => $identity->id]);
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (!$membership) {
            TenantMembership::create([
                'identity_id' => $identity->id,
                'tenant_id' => $tenant->id,
                'role' => 'tenant_admin',
                'is_enabled' => true,
            ]);
        } else {
            if ($membership->role !== 'tenant_admin' || !$membership->is_enabled) {
                $membership->update(['role' => 'tenant_admin', 'is_enabled' => true]);
            }
        }

        return $identity;
    }
    /**
     * Build platform_audit_log payload. For identity-based platform auth (no User):
     * actor_user_id = null, identity stored in metadata. For legacy User-based: actor_user_id set.
     */
    private function platformAuditPayload(Request $request, string $action, ?string $targetTenantId, ?string $targetEntityType, ?string $targetEntityId, array $metadata = []): array
    {
        $identity = $request->attributes->get('identity');
        $identityId = $request->attributes->get('identity_id');
        if ($identity !== null || $identityId !== null) {
            $id = $identityId ?? ($identity ? $identity->id : null);
            $email = $identity && isset($identity->email) ? $identity->email : null;
            return [
                'actor_user_id' => null,
                'action' => $action,
                'target_tenant_id' => $targetTenantId,
                'target_entity_type' => $targetEntityType,
                'target_entity_id' => $targetEntityId,
                'metadata' => array_merge($metadata, array_filter([
                    'actor_identity_id' => $id,
                    'actor_email' => $email,
                ])),
            ];
        }
        // Only use user_id for actor_user_id if it exists in users (defensive: client may send identity id as X-User-Id).
        $userId = $request->attributes->get('user_id');
        $actorUserId = null;
        $extraMeta = [];
        if ($userId !== null && $userId !== '') {
            if (User::where('id', $userId)->exists()) {
                $actorUserId = $userId;
            } else {
                // Likely identity id sent as X-User-Id; store in metadata so audit still identifies actor.
                $identity = Identity::find($userId);
                if ($identity) {
                    $extraMeta['actor_identity_id'] = $identity->id;
                    if (isset($identity->email)) {
                        $extraMeta['actor_email'] = $identity->email;
                    }
                }
            }
        }

        return [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_tenant_id' => $targetTenantId,
            'target_entity_type' => $targetEntityType,
            'target_entity_id' => $targetEntityId,
            'metadata' => array_merge($metadata, $extraMeta),
        ];
    }
    /**
     * Reset tenant admin password. Platform admin only.
     * Generates a one-time token; returns it to the caller (no email infra).
     * Logs to platform_audit_log.
     * POST /api/platform/tenants/{id}/reset-admin-password
     */
    public function resetAdminPassword(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $admin = User::where('tenant_id', $tenant->id)->where('role', 'tenant_admin')->first();
        if (!$admin) {
            return response()->json(['error' => 'Tenant has no admin user'], 404);
        }

        $identity = $this->resolveOrCreateIdentityForTenantAdmin($admin, $tenant);

        $newPassword = $request->input('new_password');
        if ($newPassword !== null && $newPassword !== '') {
            $passwordHash = Hash::make($newPassword);
            $admin->update(['password' => $passwordHash]);
            if ($identity) {
                $identity->update([
                    'password_hash' => $passwordHash,
                    'token_version' => $identity->token_version + 1,
                ]);
            }
            $token = null;
        } else {
            $token = PasswordResetToken::createToken($admin->id, 60 * 24); // 24h
        }

        PlatformAuditLog::create($this->platformAuditPayload(
            $request,
            PlatformAuditLog::ACTION_TENANT_PASSWORD_RESET,
            $tenant->id,
            'User',
            $admin->id,
            [
                'admin_email' => $admin->email,
                'token_returned' => $token !== null,
            ]
        ));

        return response()->json([
            'message' => $token ? 'Reset token generated. Provide it to the tenant admin to set a new password.' : 'Password updated.',
            'reset_token' => $token,
            'expires_in_minutes' => $token ? 60 * 24 : null,
        ]);
    }

    /**
     * Archive a tenant. Platform admin only. Logs to platform_audit_log.
     * POST /api/platform/tenants/{id}/archive
     */
    public function archive(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        if ($tenant->status === Tenant::STATUS_ARCHIVED) {
            return response()->json(['message' => 'Tenant is already archived'], 200);
        }

        $tenant->update(['status' => Tenant::STATUS_ARCHIVED]);

        PlatformAuditLog::create($this->platformAuditPayload(
            $request,
            PlatformAuditLog::ACTION_TENANT_ARCHIVE,
            $tenant->id,
            'Tenant',
            $tenant->id,
            ['tenant_name' => $tenant->name]
        ));

        return response()->json([
            'message' => 'Tenant archived',
            'id' => $tenant->id,
            'status' => $tenant->status,
        ]);
    }

    /**
     * Unarchive a tenant. Platform admin only. Logs to platform_audit_log.
     * POST /api/platform/tenants/{id}/unarchive
     */
    public function unarchive(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        if ($tenant->status !== Tenant::STATUS_ARCHIVED) {
            return response()->json(['message' => 'Tenant is not archived'], 200);
        }

        $tenant->update(['status' => Tenant::STATUS_ACTIVE]);

        PlatformAuditLog::create($this->platformAuditPayload(
            $request,
            PlatformAuditLog::ACTION_TENANT_UNARCHIVE,
            $tenant->id,
            'Tenant',
            $tenant->id,
            ['tenant_name' => $tenant->name]
        ));

        return response()->json([
            'message' => 'Tenant unarchived',
            'id' => $tenant->id,
            'status' => $tenant->status,
        ]);
    }
}
