<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldJobMachine extends Model
{
    use HasUuids;

    protected $table = 'field_job_machines';

    /** How financial rate/amount were determined (nullable until Phase 2 posting persists snapshots). */
    public const PRICING_BASIS_RATE_CARD = 'RATE_CARD';

    public const PRICING_BASIS_MANUAL = 'MANUAL';

    public const PRICING_BASIS_COST_PLUS = 'COST_PLUS';

    protected $fillable = [
        'tenant_id',
        'field_job_id',
        'machine_id',
        'usage_qty',
        'meter_unit_snapshot',
        'pricing_basis',
        'rate_snapshot',
        'rate_card_id',
        'amount',
        'source_work_log_id',
        'source_charge_id',
    ];

    protected $casts = [
        'usage_qty' => 'decimal:2',
        'rate_snapshot' => 'decimal:6',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fieldJob(): BelongsTo
    {
        return $this->belongsTo(FieldJob::class, 'field_job_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(MachineRateCard::class, 'rate_card_id');
    }

    public function sourceWorkLog(): BelongsTo
    {
        return $this->belongsTo(MachineWorkLog::class, 'source_work_log_id');
    }

    /**
     * Optional link to a machinery charge document (same as sourceMachineryCharge).
     * Column name remains source_charge_id for Phase 1 compatibility.
     */
    public function sourceCharge(): BelongsTo
    {
        return $this->belongsTo(MachineryCharge::class, 'source_charge_id');
    }

    public function sourceMachineryCharge(): BelongsTo
    {
        return $this->sourceCharge();
    }

    public function hasCostingSnapshot(): bool
    {
        return $this->amount !== null
            || $this->rate_snapshot !== null
            || $this->pricing_basis !== null
            || $this->rate_card_id !== null;
    }

    public function isLinkedToMachineryCharge(): bool
    {
        return $this->source_charge_id !== null;
    }
}
