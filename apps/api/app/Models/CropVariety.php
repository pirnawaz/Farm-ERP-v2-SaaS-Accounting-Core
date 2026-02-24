<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropVariety extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'tenant_crop_item_id',
        'name',
        'is_active',
        'maturity_days',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tenantCropItem(): BelongsTo
    {
        return $this->belongsTo(TenantCropItem::class, 'tenant_crop_item_id');
    }

    public function cropCycles(): HasMany
    {
        return $this->hasMany(CropCycle::class, 'crop_variety_id');
    }
}
