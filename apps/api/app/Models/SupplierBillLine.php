<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBillLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'supplier_bill_id',
        'line_no',
        'description',
        'project_id',
        'crop_cycle_id',
        'cost_category',
        'qty',
        'cash_unit_price',
        'credit_unit_price',
        'base_cash_amount',
        'selected_unit_price',
        'credit_premium_amount',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:6',
        'cash_unit_price' => 'decimal:6',
        'credit_unit_price' => 'decimal:6',
        'base_cash_amount' => 'decimal:2',
        'selected_unit_price' => 'decimal:6',
        'credit_premium_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SupplierBillLineMatch::class, 'supplier_bill_line_id');
    }
}

