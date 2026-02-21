<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetToken;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PlatformTenantLifecycleController extends Controller
{
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

        $newPassword = $request->input('new_password');
        if ($newPassword !== null && $newPassword !== '') {
            $admin->update(['password' => Hash::make($newPassword)]);
            $token = null;
        } else {
            $token = PasswordResetToken::createToken($admin->id, 60 * 24); // 24h
        }

        $actorUserId = $request->attributes->get('user_id');
        PlatformAuditLog::create([
            'actor_user_id' => $actorUserId,
            'action' => PlatformAuditLog::ACTION_TENANT_PASSWORD_RESET,
            'target_tenant_id' => $tenant->id,
            'target_entity_type' => 'User',
            'target_entity_id' => $admin->id,
            'metadata' => [
                'admin_email' => $admin->email,
                'token_returned' => $token !== null,
            ],
        ]);

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

        $actorUserId = $request->attributes->get('user_id');
        PlatformAuditLog::create([
            'actor_user_id' => $actorUserId,
            'action' => PlatformAuditLog::ACTION_TENANT_ARCHIVE,
            'target_tenant_id' => $tenant->id,
            'target_entity_type' => 'Tenant',
            'target_entity_id' => $tenant->id,
            'metadata' => ['tenant_name' => $tenant->name],
        ]);

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

        $actorUserId = $request->attributes->get('user_id');
        PlatformAuditLog::create([
            'actor_user_id' => $actorUserId,
            'action' => PlatformAuditLog::ACTION_TENANT_UNARCHIVE,
            'target_tenant_id' => $tenant->id,
            'target_entity_type' => 'Tenant',
            'target_entity_id' => $tenant->id,
            'metadata' => ['tenant_name' => $tenant->name],
        ]);

        return response()->json([
            'message' => 'Tenant unarchived',
            'id' => $tenant->id,
            'status' => $tenant->status,
        ]);
    }
}
