<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Settlement extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'project_id', // For backward compatibility with old project-based settlements
        'posting_group_id',
        'pool_revenue', // For backward compatibility
        'shared_costs', // For backward compatibility
        'pool_profit', // For backward compatibility
        'kamdari_amount', // For backward compatibility
        'landlord_share', // For backward compatibility
        'hari_share', // For backward compatibility
        'hari_only_deductions', // For backward compatibility
        // New fields for sales-based settlements
        'settlement_no',
        'share_rule_id',
        'crop_cycle_id',
        'from_date',
        'to_date',
        'basis_amount',
        'status',
        'posting_date',
        'reversal_posting_group_id',
        'posted_at',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'pool_revenue' => 'decimal:2',
        'shared_costs' => 'decimal:2',
        'pool_profit' => 'decimal:2',
        'kamdari_amount' => 'decimal:2',
        'landlord_share' => 'decimal:2',
        'hari_share' => 'decimal:2',
        'hari_only_deductions' => 'decimal:2',
        // New casts
        'from_date' => 'date',
        'to_date' => 'date',
        'basis_amount' => 'decimal:2',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
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

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function offsets(): HasMany
    {
        return $this->hasMany(SettlementOffset::class);
    }

    // New relationships for sales-based settlements
    public function shareRule(): BelongsTo
    {
        return $this->belongsTo(ShareRule::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }

    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class, 'settlement_sales', 'settlement_id', 'sale_id')
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Helper methods
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
}
