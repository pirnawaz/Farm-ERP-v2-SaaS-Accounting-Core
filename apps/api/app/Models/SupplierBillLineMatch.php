<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLineMatch extends Model
{
    use HasUuids;

    protected $table = 'supplier_bill_line_matches';

    protected $fillable = [
        'tenant_id',
        'supplier_bill_line_id',
        'purchase_order_line_id',
        'grn_line_id',
        'matched_qty',
        'matched_amount',
    ];

    protected $casts = [
        'matched_qty' => 'decimal:6',
        'matched_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplierBillLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBillLine::class, 'supplier_bill_line_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    public function grnLine(): BelongsTo
    {
        return $this->belongsTo(InvGrnLine::class, 'grn_line_id');
    }
}

