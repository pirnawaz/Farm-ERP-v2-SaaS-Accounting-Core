<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvItem extends Model
{
    use HasUuids;

    protected $table = 'inv_items';

    protected $fillable = [
        'tenant_id',
        'name',
        'sku',
        'category_id',
        'uom_id',
        'valuation_method',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InvItemCategory::class, 'category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(InvUom::class, 'uom_id');
    }

    public function grnLines(): HasMany
    {
        return $this->hasMany(InvGrnLine::class, 'item_id');
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(InvIssueLine::class, 'item_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InvStockBalance::class, 'item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(InvStockMovement::class, 'item_id');
    }
}
