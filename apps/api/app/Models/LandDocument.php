<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'land_parcel_id',
        'file_path',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function landParcel(): BelongsTo
    {
        return $this->belongsTo(LandParcel::class);
    }
}
