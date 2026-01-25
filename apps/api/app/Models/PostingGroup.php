<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostingGroup extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'crop_cycle_id',
        'source_type',
        'source_id',
        'posting_date',
        'idempotency_key',
        'reversal_of_posting_group_id',
        'correction_reason',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function allocationRows(): HasMany
    {
        return $this->hasMany(AllocationRow::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_of_posting_group_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(PostingGroup::class, 'reversal_of_posting_group_id');
    }
}
