<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MachineryCharge extends Model
{
    use HasUuids;

    protected $table = 'machinery_charges';

    // Status constants
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_REVERSED = 'REVERSED';

    // Pool scope constants (matching MachineWorkLog)
    public const POOL_SCOPE_SHARED = 'SHARED';
    public const POOL_SCOPE_HARI_ONLY = 'HARI_ONLY';

    protected $fillable = [
        'tenant_id',
        'charge_no',
        'status',
        'landlord_party_id',
        'project_id',
        'crop_cycle_id',
        'pool_scope',
        'charge_date',
        'posting_date',
        'posted_at',
        'total_amount',
        'posting_group_id',
        'reversal_posting_group_id',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'total_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function landlordParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'landlord_party_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MachineryChargeLine::class, 'machinery_charge_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
