<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineryService extends Model
{
    use HasUuids;

    protected $table = 'machinery_services';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_REVERSED = 'REVERSED';

    public const ALLOCATION_SCOPE_SHARED = 'SHARED';
    public const ALLOCATION_SCOPE_HARI_ONLY = 'HARI_ONLY';

    protected $fillable = [
        'tenant_id',
        'machine_id',
        'project_id',
        'rate_card_id',
        'quantity',
        'amount',
        'allocation_scope',
        'in_kind_item_id',
        'in_kind_rate_per_unit',
        'in_kind_quantity',
        'in_kind_store_id',
        'in_kind_inventory_issue_id',
        'posting_date',
        'status',
        'posting_group_id',
        'reversal_posting_group_id',
        'posted_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'amount' => 'decimal:2',
        'in_kind_rate_per_unit' => 'decimal:4',
        'in_kind_quantity' => 'decimal:4',
        'posting_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(MachineRateCard::class, 'rate_card_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function inKindItem(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'in_kind_item_id');
    }

    public function inKindStore(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'in_kind_store_id');
    }

    public function inKindInventoryIssue(): BelongsTo
    {
        return $this->belongsTo(InvIssue::class, 'in_kind_inventory_issue_id');
    }

    public function hasInKindPayment(): bool
    {
        return $this->in_kind_item_id !== null && $this->in_kind_rate_per_unit !== null;
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
