<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementPack extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_FINALIZED = 'FINALIZED';

    public const STATUS_VOID = 'VOID';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'crop_cycle_id',
        'status',
        'reference_no',
        'prepared_by_user_id',
        'finalized_by_user_id',
        'prepared_at',
        'finalized_at',
        'as_of_date',
        'notes',
    ];

    protected $casts = [
        'prepared_at' => 'datetime',
        'finalized_at' => 'datetime',
        'as_of_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function preparedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }

    public function finalizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SettlementPackApproval::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SettlementPackVersion::class);
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(SettlementPackSignoff::class);
    }

    public function latestVersion(): ?SettlementPackVersion
    {
        return $this->versions()->orderByDesc('version_no')->first();
    }

    /** Snapshot JSON for the current pack snapshot (latest version row). */
    public function snapshotJson(): array
    {
        $v = $this->latestVersion();

        return $v?->snapshot_json ?? [];
    }

    public function isReadOnly(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }
}
