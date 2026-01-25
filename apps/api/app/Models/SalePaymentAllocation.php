<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePaymentAllocation extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'payment_id',
        'posting_group_id',
        'allocation_date',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocation_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    /**
     * Scope to filter allocations by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
