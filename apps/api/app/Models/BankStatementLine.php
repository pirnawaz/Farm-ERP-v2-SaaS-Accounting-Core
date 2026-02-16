<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementLine extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_VOID = 'VOID';

    protected $fillable = [
        'tenant_id',
        'bank_reconciliation_id',
        'line_date',
        'amount',
        'description',
        'reference',
        'status',
        'created_by',
        'voided_by',
        'voided_at',
    ];

    protected $casts = [
        'line_date' => 'date',
        'amount' => 'decimal:2',
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

    public function matches(): HasMany
    {
        return $this->hasMany(BankStatementMatch::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeVoided($query)
    {
        return $query->where('status', self::STATUS_VOID);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
