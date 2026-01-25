<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'buyer_party_id',
        'project_id',
        'crop_cycle_id',
        'amount',
        'posting_date',
        'sale_no',
        'sale_date',
        'due_date',
        'status',
        'posting_group_id',
        'posted_at',
        'notes',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posting_date' => 'date',
        'sale_date' => 'date',
        'due_date' => 'date',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function buyerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function paymentAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalePaymentAllocation::class);
    }

    /**
     * Scope to filter sales by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter draft sales.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    /**
     * Scope to filter posted sales.
     */
    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    /**
     * Check if sale can be updated (only DRAFT status).
     */
    public function canBeUpdated(): bool
    {
        return $this->status === 'DRAFT';
    }

    /**
     * Check if sale can be deleted (only DRAFT status).
     */
    public function canBeDeleted(): bool
    {
        return $this->status === 'DRAFT';
    }
}
