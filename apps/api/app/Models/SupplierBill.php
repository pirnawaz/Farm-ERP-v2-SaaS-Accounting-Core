<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_POSTED = 'POSTED';
    public const STATUS_PARTIALLY_PAID = 'PARTIALLY_PAID';
    public const STATUS_PAID = 'PAID';

    public const TERMS_CASH = 'CASH';
    public const TERMS_CREDIT = 'CREDIT';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'reference_no',
        'bill_date',
        'due_date',
        'currency_code',
        'payment_terms',
        'status',
        'payment_status',
        'paid_amount',
        'outstanding_amount',
        'posting_group_id',
        'posting_date',
        'posted_at',
        'posted_by',
        'subtotal_cash_amount',
        'credit_premium_total',
        'grand_total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'posting_date' => 'date',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'subtotal_cash_amount' => 'decimal:2',
        'credit_premium_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
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

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }
}

