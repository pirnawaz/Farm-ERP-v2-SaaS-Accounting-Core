<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineryChargeLine extends Model
{
    use HasUuids;

    protected $table = 'machinery_charge_lines';

    // Unit constants
    public const UNIT_HOUR = 'HOUR';
    public const UNIT_KM = 'KM';
    public const UNIT_JOB = 'JOB';

    protected $fillable = [
        'tenant_id',
        'machinery_charge_id',
        'machine_work_log_id',
        'usage_qty',
        'unit',
        'rate',
        'amount',
        'rate_card_id',
    ];

    protected $casts = [
        'usage_qty' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(MachineryCharge::class, 'machinery_charge_id');
    }

    public function workLog(): BelongsTo
    {
        return $this->belongsTo(MachineWorkLog::class, 'machine_work_log_id');
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(MachineRateCard::class, 'rate_card_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
