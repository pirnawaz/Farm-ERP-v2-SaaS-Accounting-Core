<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementAllocation extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ENDED = 'ENDED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'tenant_id',
        'agreement_id',
        'land_parcel_id',
        'allocated_area',
        'area_uom',
        'starts_on',
        'ends_on',
        'status',
        'label',
        'notes',
        'legacy_field_id',
        'backfilled_for_project_id',
    ];

    protected $casts = [
        'allocated_area' => 'decimal:4',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'agreement_id');
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class, 'land_parcel_id');
    }

    public function legacyField(): BelongsTo
    {
        return $this->belongsTo(FieldBlock::class, 'legacy_field_id');
    }

    public function backfilledForProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'backfilled_for_project_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Whether this allocation is active for a calendar date (inclusive range; open-ended when ends_on is null).
     */
    public function isActiveOn(\DateTimeInterface|string $date): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }
        $d = \Carbon\Carbon::parse($date)->startOfDay();
        if ($d->lt(\Carbon\Carbon::parse($this->starts_on)->startOfDay())) {
            return false;
        }
        if ($this->ends_on !== null && $d->gt(\Carbon\Carbon::parse($this->ends_on)->startOfDay())) {
            return false;
        }

        return true;
    }
}
