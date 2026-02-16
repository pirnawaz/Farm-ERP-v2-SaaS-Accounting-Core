<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'party_id',
        'direction',
        'amount',
        'payment_date',
        'method',
        'reference',
        'status',
        'posting_group_id',
        'reversal_posting_group_id',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'settlement_id',
        'notes',
        'purpose',
        'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function reversalPostingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'reversal_posting_group_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function saleAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SalePaymentAllocation::class);
    }

    public function grnAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GrnPaymentAllocation::class);
    }

    /**
     * Scope to filter payments by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter draft payments.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'DRAFT');
    }

    /**
     * Scope to filter posted payments.
     */
    public function scopePosted($query)
    {
        return $query->where('status', 'POSTED');
    }

    /**
     * Scope to exclude reversed payments (for balance/statement totals).
     */
    public function scopeNotReversed($query)
    {
        return $query->whereNull('reversal_posting_group_id');
    }
}
