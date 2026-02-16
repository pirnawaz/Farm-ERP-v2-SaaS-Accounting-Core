<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnPaymentAllocation extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_VOID = 'VOID';

    protected $table = 'grn_payment_allocations';

    protected $fillable = [
        'tenant_id',
        'grn_id',
        'payment_id',
        'posting_group_id',
        'allocation_date',
        'amount',
        'status',
        'created_by',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocation_date' => 'date',
        'created_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(InvGrn::class, 'grn_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeVoided($query)
    {
        return $query->where('status', self::STATUS_VOID);
    }
}
