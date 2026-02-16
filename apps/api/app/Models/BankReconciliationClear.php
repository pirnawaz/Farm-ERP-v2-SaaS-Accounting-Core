<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationClear extends Model
{
    use HasUuids;

    public const STATUS_CLEARED = 'CLEARED';
    public const STATUS_VOID = 'VOID';

    protected $fillable = [
        'tenant_id',
        'bank_reconciliation_id',
        'ledger_entry_id',
        'cleared_date',
        'status',
        'created_by',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'cleared_date' => 'date',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeCleared($query)
    {
        return $query->where('status', self::STATUS_CLEARED);
    }

    public function scopeVoided($query)
    {
        return $query->where('status', self::STATUS_VOID);
    }
}
