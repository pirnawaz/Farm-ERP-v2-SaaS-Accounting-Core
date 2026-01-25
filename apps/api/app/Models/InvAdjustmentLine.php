<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvAdjustmentLine extends Model
{
    use HasUuids;

    protected $table = 'inv_adjustment_lines';

    protected $fillable = [
        'tenant_id',
        'adjustment_id',
        'item_id',
        'qty_delta',
        'unit_cost_snapshot',
        'line_total',
    ];

    protected $casts = [
        'qty_delta' => 'decimal:6',
        'unit_cost_snapshot' => 'decimal:6',
        'line_total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InvAdjustment::class, 'adjustment_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }
}
