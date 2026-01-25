<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'party_id',
        'crop_cycle_id',
        'land_allocation_id',
        'name',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function landAllocation(): BelongsTo
    {
        return $this->belongsTo(LandAllocation::class);
    }

    public function projectRule(): HasOne
    {
        return $this->hasOne(ProjectRule::class);
    }

    public function operationalTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }

    public function settlements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
