<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropActivityType extends Model
{
    use HasUuids;

    protected $table = 'crop_activity_types';

    protected $fillable = [
        'tenant_id',
        'name',
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

    public function activities(): HasMany
    {
        return $this->hasMany(CropActivity::class, 'activity_type_id');
    }
}
