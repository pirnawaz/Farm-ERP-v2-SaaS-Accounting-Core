<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'name',
        'party_types',
    ];

    protected $casts = [
        'party_types' => 'array',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function landAllocations(): HasMany
    {
        return $this->hasMany(LandAllocation::class);
    }

    public function allocationRows(): HasMany
    {
        return $this->hasMany(AllocationRow::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'buyer_party_id');
    }
}
