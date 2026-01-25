<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropActivityLabour extends Model
{
    use HasUuids;

    protected $table = 'crop_activity_labour';

    protected $fillable = [
        'tenant_id',
        'activity_id',
        'worker_id',
        'rate_basis',
        'units',
        'rate',
        'amount',
    ];

    protected $casts = [
        'units' => 'decimal:6',
        'rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CropActivity::class, 'activity_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(LabWorker::class, 'worker_id');
    }
}
