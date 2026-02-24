<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropCatalogItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'default_name',
        'scientific_name',
        'category',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenantCropItems(): HasMany
    {
        return $this->hasMany(TenantCropItem::class, 'crop_catalog_item_id');
    }
}
