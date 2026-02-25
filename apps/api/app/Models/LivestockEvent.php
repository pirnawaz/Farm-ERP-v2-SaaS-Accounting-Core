<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestockEvent extends Model
{
    use HasUuids;

    protected $table = 'livestock_events';

    public const TYPE_PURCHASE = 'PURCHASE';
    public const TYPE_SALE = 'SALE';
    public const TYPE_BIRTH = 'BIRTH';
    public const TYPE_DEATH = 'DEATH';
    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    protected $fillable = [
        'tenant_id',
        'production_unit_id',
        'event_date',
        'event_type',
        'quantity',
        'notes',
    ];

    protected $casts = [
        'event_date' => 'date',
        'quantity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class, 'production_unit_id');
    }
}
