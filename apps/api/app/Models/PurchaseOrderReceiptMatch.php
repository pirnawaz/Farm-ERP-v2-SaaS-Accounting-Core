<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderReceiptMatch extends Model
{
    use HasUuids;

    protected $table = 'purchase_order_receipt_matches';

    protected $fillable = [
        'tenant_id',
        'purchase_order_line_id',
        'grn_line_id',
        'matched_qty',
    ];

    protected $casts = [
        'matched_qty' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

