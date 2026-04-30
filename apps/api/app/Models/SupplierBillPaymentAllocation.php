<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillPaymentAllocation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'supplier_bill_payment_allocations';

    protected $fillable = [
        'tenant_id',
        'supplier_payment_id',
        'supplier_bill_id',
        'amount_applied',
    ];

    protected $casts = [
        'amount_applied' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }
}

