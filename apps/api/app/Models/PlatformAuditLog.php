<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditLog extends Model
{
    use HasUuids;

    protected $table = 'platform_audit_log';

    public const UPDATED_AT = null;

    public const ACTION_TENANT_PASSWORD_RESET = 'tenant_password_reset';
    public const ACTION_TENANT_ARCHIVE = 'tenant_archive';
    public const ACTION_TENANT_UNARCHIVE = 'tenant_unarchive';

    protected $fillable = [
        'actor_user_id',
        'action',
        'target_tenant_id',
        'target_entity_type',
        'target_entity_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'target_tenant_id');
    }
}
