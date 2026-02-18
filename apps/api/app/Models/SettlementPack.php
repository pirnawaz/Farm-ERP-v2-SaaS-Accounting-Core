<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPack extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const STATUS_FINAL = 'FINAL';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'generated_by_user_id',
        'generated_at',
        'status',
        'finalized_at',
        'finalized_by_user_id',
        'summary_json',
        'register_version',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'summary_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function finalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function approvals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SettlementPackApproval::class);
    }
}
