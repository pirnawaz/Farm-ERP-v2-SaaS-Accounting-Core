<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineRateCard extends Model
{
    use HasUuids;

    protected $table = 'machine_rate_cards';

    // Applies to mode constants
    public const APPLIES_TO_MACHINE = 'MACHINE';
    public const APPLIES_TO_MACHINE_TYPE = 'MACHINE_TYPE';

    // Rate unit constants
    public const RATE_UNIT_HOUR = 'HOUR';
    public const RATE_UNIT_KM = 'KM';
    public const RATE_UNIT_JOB = 'JOB';

    // Pricing model constants
    public const PRICING_MODEL_FIXED = 'FIXED';
    public const PRICING_MODEL_COST_PLUS = 'COST_PLUS';

    protected $fillable = [
        'tenant_id',
        'applies_to_mode',
        'machine_id',
        'machine_type',
        'activity_type_id',
        'effective_from',
        'effective_to',
        'rate_unit',
        'pricing_model',
        'base_rate',
        'cost_plus_percent',
        'includes_fuel',
        'includes_operator',
        'includes_maintenance',
        'is_active',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'base_rate' => 'decimal:2',
        'cost_plus_percent' => 'decimal:2',
        'includes_fuel' => 'boolean',
        'includes_operator' => 'boolean',
        'includes_maintenance' => 'boolean',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(CropActivityType::class, 'activity_type_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
