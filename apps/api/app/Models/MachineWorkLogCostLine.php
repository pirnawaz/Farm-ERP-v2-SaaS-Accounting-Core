<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineWorkLogCostLine extends Model
{
    use HasUuids;

    protected $table = 'machine_work_log_cost_lines';

    // Cost code constants
    public const COST_CODE_FUEL = 'FUEL';
    public const COST_CODE_OPERATOR = 'OPERATOR';
    public const COST_CODE_MAINTENANCE = 'MAINTENANCE';
    public const COST_CODE_OTHER = 'OTHER';

    protected $fillable = [
        'tenant_id',
        'machine_work_log_id',
        'cost_code',
        'description',
        'amount',
        'party_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workLog(): BelongsTo
    {
        return $this->belongsTo(MachineWorkLog::class, 'machine_work_log_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
