<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FieldBlock extends Model
{
    use HasUuids;

    protected $table = 'field_blocks';

    protected $fillable = [
        'tenant_id',
        'crop_cycle_id',
        'land_parcel_id',
        'tenant_crop_item_id',
        'name',
        'area',
    ];

    protected $casts = [
        'area' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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

    public function tenantCropItem(): BelongsTo
    {
        return $this->belongsTo(TenantCropItem::class, 'tenant_crop_item_id');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'field_block_id');
    }
}
