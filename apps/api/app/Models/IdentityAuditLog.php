<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityAuditLog extends Model
{
    use HasUuids;

    protected $table = 'identity_audit_log';

    public const UPDATED_AT = null;

    public const ACTION_PLATFORM_LOGIN_SUCCESS = 'platform_login_success';
    public const ACTION_PLATFORM_LOGIN_FAILURE = 'platform_login_failure';
    public const ACTION_TENANT_LOGIN_SUCCESS = 'tenant_login_success';
    public const ACTION_TENANT_LOGIN_FAILURE = 'tenant_login_failure';
    public const ACTION_INVITATION_CREATED = 'invitation_created';
    public const ACTION_PLATFORM_INVITATION_CREATED = 'platform_invitation_created';
    public const ACTION_INVITATION_ACCEPTED = 'invitation_accepted';
    public const ACTION_USER_ROLE_CHANGED = 'user_role_changed';
    public const ACTION_TENANT_USER_CREATED_MANUAL = 'tenant_user_created_manual';
    public const ACTION_PLATFORM_USER_CREATED_MANUAL = 'platform_user_created_manual';
    public const ACTION_PLATFORM_USER_ROLE_CHANGED = 'platform_user_role_changed';
    public const ACTION_PLATFORM_USER_ENABLED_CHANGED = 'platform_user_enabled_changed';
    public const ACTION_FIRST_LOGIN_PASSWORD_SET = 'first_login_password_set';
    public const ACTION_IMPERSONATION_START = 'impersonation_start';
    public const ACTION_IMPERSONATION_STOP = 'impersonation_stop';
    public const ACTION_PLATFORM_LOGOUT_ALL = 'platform_logout_all';
    public const ACTION_TENANT_LOGOUT_ALL = 'tenant_logout_all';

    protected $fillable = [
        'request_id',
        'tenant_id',
        'actor_user_id',
        'action',
        'metadata',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
