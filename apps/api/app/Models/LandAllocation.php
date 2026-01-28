<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandAllocation extends Model
{
    use HasUuids;

    public $timestamps = true;
    const UPDATED_AT = null; // Table doesn't have updated_at column

    protected $fillable = [
        'tenant_id',
        'crop_cycle_id',
        'land_parcel_id',
        'party_id',
        'allocated_acres',
    ];

    protected $casts = [
        'allocated_acres' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected $appends = ['allocation_mode'];

    public function getAllocationModeAttribute(): string
    {
        return $this->party_id === null ? 'OWNER' : 'HARI';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
