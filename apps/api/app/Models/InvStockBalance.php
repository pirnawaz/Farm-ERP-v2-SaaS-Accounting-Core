<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvStockBalance extends Model
{
    use HasUuids;

    protected $table = 'inv_stock_balances';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'store_id',
        'item_id',
        'qty_on_hand',
        'value_on_hand',
        'wac_cost',
        'updated_at',
    ];

    protected $casts = [
        'qty_on_hand' => 'decimal:6',
        'value_on_hand' => 'decimal:2',
        'wac_cost' => 'decimal:6',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
