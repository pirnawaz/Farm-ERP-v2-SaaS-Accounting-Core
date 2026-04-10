<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestShareLine extends Model
{
    use HasUuids;

    protected $table = 'harvest_share_lines';

    public const RECIPIENT_OWNER = 'OWNER';

    public const RECIPIENT_MACHINE = 'MACHINE';

    public const RECIPIENT_LABOUR = 'LABOUR';

    public const RECIPIENT_LANDLORD = 'LANDLORD';

    public const RECIPIENT_CONTRACTOR = 'CONTRACTOR';

    public const SETTLEMENT_IN_KIND = 'IN_KIND';

    public const SETTLEMENT_CASH = 'CASH';

    public const BASIS_FIXED_QTY = 'FIXED_QTY';

    public const BASIS_PERCENT = 'PERCENT';

    public const BASIS_RATIO = 'RATIO';

    public const BASIS_REMAINDER = 'REMAINDER';

    protected $fillable = [
        'tenant_id',
        'harvest_id',
        'harvest_line_id',
        'recipient_role',
        'settlement_mode',
        'beneficiary_party_id',
        'machine_id',
        'worker_id',
        'source_field_job_id',
        'source_lab_work_log_id',
        'source_machinery_charge_id',
        'source_settlement_id',
        'inventory_item_id',
        'store_id',
        'share_basis',
        'share_value',
        'ratio_numerator',
        'ratio_denominator',
        'computed_qty',
        'computed_unit_cost_snapshot',
        'computed_value_snapshot',
        'remainder_bucket',
        'sort_order',
        'rule_snapshot',
        'notes',
    ];

    protected $casts = [
        'share_value' => 'decimal:6',
        'ratio_numerator' => 'decimal:6',
        'ratio_denominator' => 'decimal:6',
        'computed_qty' => 'decimal:3',
        'computed_unit_cost_snapshot' => 'decimal:6',
        'computed_value_snapshot' => 'decimal:2',
        'remainder_bucket' => 'boolean',
        'sort_order' => 'integer',
        'rule_snapshot' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function harvestLine(): BelongsTo
    {
        return $this->belongsTo(HarvestLine::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function beneficiaryParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'beneficiary_party_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(LabWorker::class, 'worker_id');
    }

    public function sourceFieldJob(): BelongsTo
    {
        return $this->belongsTo(FieldJob::class, 'source_field_job_id');
    }

    public function sourceLabWorkLog(): BelongsTo
    {
        return $this->belongsTo(LabWorkLog::class, 'source_lab_work_log_id');
    }

    public function sourceMachineryCharge(): BelongsTo
    {
        return $this->belongsTo(MachineryCharge::class, 'source_machinery_charge_id');
    }

    public function sourceSettlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'source_settlement_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(InvStore::class, 'store_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InvItem::class, 'inventory_item_id');
    }

    public function isInKind(): bool
    {
        return $this->settlement_mode === self::SETTLEMENT_IN_KIND;
    }

    public function isCash(): bool
    {
        return $this->settlement_mode === self::SETTLEMENT_CASH;
    }

    public function isRemainderBucket(): bool
    {
        return (bool) $this->remainder_bucket;
    }

    public function isOwner(): bool
    {
        return $this->recipient_role === self::RECIPIENT_OWNER;
    }

    public function isMachine(): bool
    {
        return $this->recipient_role === self::RECIPIENT_MACHINE;
    }

    public function isLabour(): bool
    {
        return $this->recipient_role === self::RECIPIENT_LABOUR;
    }

    public function isLandlord(): bool
    {
        return $this->recipient_role === self::RECIPIENT_LANDLORD;
    }

    public function isContractor(): bool
    {
        return $this->recipient_role === self::RECIPIENT_CONTRACTOR;
    }

    public function usesFixedQty(): bool
    {
        return $this->share_basis === self::BASIS_FIXED_QTY;
    }

    public function usesPercent(): bool
    {
        return $this->share_basis === self::BASIS_PERCENT;
    }

    public function usesRatio(): bool
    {
        return $this->share_basis === self::BASIS_RATIO;
    }

    public function usesRemainder(): bool
    {
        return $this->share_basis === self::BASIS_REMAINDER;
    }
}
