<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropActivityInput extends Model
{
    use HasUuids;

    protected $table = 'crop_activity_inputs';

    protected $fillable = [
        'tenant_id',
        'activity_id',
        'store_id',
        'item_id',
        'qty',
        'unit_cost_snapshot',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:6',
        'unit_cost_snapshot' => 'decimal:6',
        'line_total' => 'decimal:2',
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'store_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'item_id');
    }
}
