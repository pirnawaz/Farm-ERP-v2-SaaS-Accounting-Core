<?php

namespace App\Domains\Commercial\Payables;

use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoiceLine extends Model
{
    use HasUuids;

    protected $table = 'supplier_invoice_lines';

    protected $fillable = [
        'tenant_id',
        'supplier_invoice_id',
        'line_no',
        'description',
        'item_id',
        'qty',
        'unit_price',
        'line_total',
        'tax_amount',
        'grn_line_id',
    ];

    protected $casts = [
        'qty' => 'decimal:6',
        'unit_price' => 'decimal:6',
        'line_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }

    public function grnLine(): BelongsTo
    {
        return $this->belongsTo(InvGrnLine::class, 'grn_line_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SupplierInvoiceMatch::class, 'supplier_invoice_line_id');
    }
}
