<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationAuditLog extends Model
{
    use HasUuids;

    protected $table = 'impersonation_audit_log';

    public const UPDATED_AT = null;

    public const ACTION_START = 'START';
    public const ACTION_STOP = 'STOP';

    protected $fillable = [
        'actor_user_id',
        'target_tenant_id',
        'target_user_id',
        'action',
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

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
