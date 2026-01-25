<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvStockMovement extends Model
{
    use HasUuids;

    protected $table = 'inv_stock_movements';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'posting_group_id',
        'movement_type',
        'store_id',
        'item_id',
        'qty_delta',
        'value_delta',
        'unit_cost_snapshot',
        'occurred_at',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'qty_delta' => 'decimal:6',
        'value_delta' => 'decimal:2',
        'unit_cost_snapshot' => 'decimal:6',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class, 'posting_group_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'store_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }
}
