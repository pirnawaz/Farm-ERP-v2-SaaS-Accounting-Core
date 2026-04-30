<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    use HasUuids;

    protected $table = 'supplier_payments';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_VOIDED = 'VOIDED';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'payment_date',
        'posting_date',
        'payment_method',
        'bank_account_id',
        'status',
        'total_amount',
        'notes',
        'posting_group_id',
        'posted_at',
        'posted_by',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'posting_date' => 'date',
        'total_amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierBillPaymentAllocation::class);
    }
}

