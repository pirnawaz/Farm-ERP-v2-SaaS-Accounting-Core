<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllocationRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'posting_group_id',
        'project_id',
        'cost_center_id',
        'party_id',
        'allocation_type',
        'allocation_scope',
        'amount',
        'currency_code',
        'base_currency_code',
        'fx_rate',
        'amount_base',
        'quantity',
        'unit',
        'machine_id',
        'rule_snapshot',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'amount_base' => 'decimal:2',
        'quantity' => 'decimal:2',
        'rule_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AllocationRow $row) {
            $base = Tenant::query()->where('id', $row->tenant_id)->value('currency_code') ?? 'GBP';
            if ($row->currency_code === null) {
                $row->currency_code = $base;
            }
            if ($row->base_currency_code === null) {
                $row->base_currency_code = $base;
            }
            if ($row->fx_rate === null) {
                $row->fx_rate = 1;
            }
            if ($row->amount_base === null && $row->amount !== null) {
                $row->amount_base = $row->amount;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function postingGroup(): BelongsTo
    {
        return $this->belongsTo(PostingGroup::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
