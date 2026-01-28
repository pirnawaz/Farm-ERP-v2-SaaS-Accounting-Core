<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvIssue extends Model
{
    use HasUuids;

    protected $table = 'inv_issues';

    protected $fillable = [
        'tenant_id',
        'doc_no',
        'store_id',
        'crop_cycle_id',
        'project_id',
        'activity_id',
        'machine_id',
        'doc_date',
        'status',
        'posting_date',
        'posting_group_id',
        'created_by',
        'allocation_mode',
        'hari_id',
        'sharing_rule_id',
        'landlord_share_pct',
        'hari_share_pct',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'posting_date' => 'date',
        'landlord_share_pct' => 'decimal:2',
        'hari_share_pct' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'store_id');
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class, 'crop_cycle_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvIssueLine::class, 'issue_id');
    }

    public function hari(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'hari_id');
    }

    public function sharingRule(): BelongsTo
    {
        return $this->belongsTo(ShareRule::class, 'sharing_rule_id');
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

    public function canBeUpdated(): bool
    {
        return $this->isDraft();
    }

    public function canBePosted(): bool
    {
        if (!$this->isDraft()) {
            return false;
        }

        return $this->validateAllocationMode();
    }

    /**
     * Validate that allocation_mode is properly configured.
     * 
     * @return bool
     */
    public function validateAllocationMode(): bool
    {
        if (!$this->allocation_mode) {
            return false;
        }

        if ($this->allocation_mode === 'HARI_ONLY') {
            return $this->hari_id !== null;
        }

        if ($this->allocation_mode === 'SHARED') {
            // Must have either sharing_rule_id or explicit percentages
            if ($this->sharing_rule_id !== null) {
                return true;
            }
            
            if ($this->landlord_share_pct !== null && $this->hari_share_pct !== null) {
                $sum = (float) $this->landlord_share_pct + (float) $this->hari_share_pct;
                return abs($sum - 100.0) < 0.01;
            }

            return false;
        }

        // FARMER_ONLY doesn't need additional fields
        return true;
    }

    public function canBeReversed(): bool
    {
        return $this->isPosted();
    }
}
