<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvStore extends Model
{
    use HasUuids;

    protected $table = 'inv_stores';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
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

    public function grns(): HasMany
    {
        return $this->hasMany(InvGrn::class, 'store_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(InvIssue::class, 'store_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(InvStockBalance::class, 'store_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(InvStockMovement::class, 'store_id');
    }
}
