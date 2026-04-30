<?php

namespace App\Models;

use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceLinePoMatch extends Model
{
    use HasUuids;

    protected $table = 'supplier_invoice_line_po_matches';

    protected $fillable = [
        'tenant_id',
        'supplier_invoice_line_id',
        'purchase_order_line_id',
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

    public function supplierInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoiceLine::class, 'supplier_invoice_line_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}

