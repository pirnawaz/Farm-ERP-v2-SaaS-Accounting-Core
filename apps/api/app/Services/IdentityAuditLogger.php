<?php

namespace App\Services;

use App\Models\IdentityAuditLog;
use Illuminate\Http\Request;

class IdentityAuditLogger
{
    public const ACTION_PLATFORM_LOGIN_SUCCESS = IdentityAuditLog::ACTION_PLATFORM_LOGIN_SUCCESS;
    public const ACTION_PLATFORM_LOGIN_FAILURE = IdentityAuditLog::ACTION_PLATFORM_LOGIN_FAILURE;
    public const ACTION_TENANT_LOGIN_SUCCESS = IdentityAuditLog::ACTION_TENANT_LOGIN_SUCCESS;
    public const ACTION_TENANT_LOGIN_FAILURE = IdentityAuditLog::ACTION_TENANT_LOGIN_FAILURE;
    public const ACTION_INVITATION_CREATED = IdentityAuditLog::ACTION_INVITATION_CREATED;
    public const ACTION_PLATFORM_INVITATION_CREATED = IdentityAuditLog::ACTION_PLATFORM_INVITATION_CREATED;
    public const ACTION_INVITATION_ACCEPTED = IdentityAuditLog::ACTION_INVITATION_ACCEPTED;
    public const ACTION_USER_ROLE_CHANGED = IdentityAuditLog::ACTION_USER_ROLE_CHANGED;
    public const ACTION_IMPERSONATION_START = IdentityAuditLog::ACTION_IMPERSONATION_START;
    public const ACTION_IMPERSONATION_STOP = IdentityAuditLog::ACTION_IMPERSONATION_STOP;
    public const ACTION_PLATFORM_LOGOUT_ALL = IdentityAuditLog::ACTION_PLATFORM_LOGOUT_ALL;
    public const ACTION_TENANT_LOGOUT_ALL = IdentityAuditLog::ACTION_TENANT_LOGOUT_ALL;

    public static function log(
        string $action,
        ?string $tenantId = null,
        ?string $actorUserId = null,
        ?array $metadata = null,
        ?Request $request = null
    ): IdentityAuditLog {
        $ip = $request?->ip();
        $userAgent = $request?->userAgent();
        if ($request && $metadata === null) {
            $metadata = [];
        }
        if ($metadata !== null && $request) {
            if (!isset($metadata['ip']) && $ip !== null) {
                $metadata['ip'] = $ip;
            }
            if (!isset($metadata['user_agent']) && $userAgent !== null) {
                $metadata['user_agent'] = $userAgent;
            }
        }
        $requestId = $request?->attributes->get('request_id');

        return IdentityAuditLog::create([
            'request_id' => $requestId,
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'metadata' => $metadata,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
