<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Harvest extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'harvest_no',
        'crop_cycle_id',
        'project_id',
        'production_unit_id',
        'land_parcel_id',
        'harvest_date',
        'posting_date',
        'status',
        'notes',
        'posted_at',
        'reversed_at',
        'posting_group_id',
        'reversal_posting_group_id',
    ];

    protected $casts = [
        'harvest_date' => 'date',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class, 'production_unit_id');
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class);
    }

    /**
     * Eager-load graph for harvest API reads (detail, post, reverse, share-line mutations).
     * Keeps share lines with posted snapshots and display relations in one place.
     *
     * @return array<int, string|\Closure>
     */
    public static function detailWithRelations(): array
    {
        return [
            'cropCycle',
            'project',
            'landParcel',
            'productionUnit',
            'postingGroup',
            'reversalPostingGroup',
            'lines.item',
            'lines.store',
            'shareLines.beneficiaryParty',
            'shareLines.machine',
            'shareLines.worker',
            'shareLines.sourceFieldJob',
            'shareLines.sourceLabWorkLog',
            'shareLines.sourceMachineryCharge',
            'shareLines.sourceSettlement',
            'shareLines.store',
            'shareLines.inventoryItem',
            'shareLines.harvestLine.item',
            'shareLines.harvestLine.store',
        ];
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(HarvestLine::class);
    }

    public function shareLines(): HasMany
    {
        return $this->hasMany(HarvestShareLine::class)->orderBy('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isPosted(): bool
    {
        return $this->status === 'POSTED';
    }

    public function isReversed(): bool
    {
        return $this->status === 'REVERSED';
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    public function scopeReversed($query)
    {
        return $query->where('status', 'REVERSED');
    }
}
