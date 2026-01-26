<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'harvest_id',
        'inventory_item_id',
        'store_id',
        'quantity',
        'uom',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'inventory_item_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'store_id');
    }
}
