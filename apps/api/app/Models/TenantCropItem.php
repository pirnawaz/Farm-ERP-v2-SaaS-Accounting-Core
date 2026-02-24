<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantCropItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'crop_catalog_item_id',
        'custom_name',
        'display_name',
        'is_active',
        'sort_order',
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

    public function cropCatalogItem(): BelongsTo
    {
        return $this->belongsTo(CropCatalogItem::class, 'crop_catalog_item_id');
    }

    public function cropVarieties(): HasMany
    {
        return $this->hasMany(CropVariety::class, 'tenant_crop_item_id');
    }

    public function cropCycles(): HasMany
    {
        return $this->hasMany(CropCycle::class, 'tenant_crop_item_id');
    }

    /**
     * Resolved display name: display_name ?? catalog default_name ?? custom_name.
     */
    public function getResolvedDisplayNameAttribute(): string
    {
        if ($this->display_name !== null && $this->display_name !== '') {
            return $this->display_name;
        }
        if ($this->cropCatalogItem) {
            return $this->cropCatalogItem->default_name;
        }
        return $this->custom_name ?? '';
    }
}
