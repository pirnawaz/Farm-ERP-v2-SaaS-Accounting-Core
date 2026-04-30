<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    use HasUuids;

    protected $table = 'purchase_order_lines';

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'line_no',
        'item_id',
        'description',
        'qty_ordered',
        'qty_overbill_tolerance',
        'expected_unit_cost',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:6',
        'qty_overbill_tolerance' => 'decimal:6',
        'expected_unit_cost' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }

    public function receiptMatches(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptMatch::class, 'purchase_order_line_id');
    }
}

