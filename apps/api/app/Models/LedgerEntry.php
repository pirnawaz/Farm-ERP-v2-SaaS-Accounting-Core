<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\LedgerWriteGuard;
use LogicException;

class LedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'posting_group_id',
        'account_id',
        'debit_amount',
        'credit_amount',
        'currency_code',
        'base_currency_code',
        'fx_rate',
        'debit_amount_base',
        'credit_amount_base',
    ];

    protected $casts = [
        'debit_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'debit_amount_base' => 'decimal:2',
        'credit_amount_base' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry) {
            if ($entry->posting_group_id === null || $entry->posting_group_id === '') {
                throw new LogicException('Ledger entries must be created with a posting_group_id (post via PostingGroup pipeline).');
            }
            LedgerWriteGuard::assertValidContext();
            if ($entry->tenant_id) {
                $ok = PostingGroup::query()
                    ->where('id', $entry->posting_group_id)
                    ->where('tenant_id', $entry->tenant_id)
                    ->exists();
                if (! $ok) {
                    throw new LogicException('Ledger entry posting_group_id must reference an existing PostingGroup for the same tenant.');
                }
            }
            $base = Tenant::query()->where('id', $entry->tenant_id)->value('currency_code') ?? 'GBP';
            if ($entry->base_currency_code === null) {
                $entry->base_currency_code = $base;
            }
            if ($entry->fx_rate === null) {
                $entry->fx_rate = 1;
            }
            if ($entry->debit_amount_base === null) {
                $entry->debit_amount_base = $entry->debit_amount;
            }
            if ($entry->credit_amount_base === null) {
                $entry->credit_amount_base = $entry->credit_amount;
            }
        });

        static::updating(function () {
            throw new LogicException('Ledger entries are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Ledger entries cannot be deleted.');
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
