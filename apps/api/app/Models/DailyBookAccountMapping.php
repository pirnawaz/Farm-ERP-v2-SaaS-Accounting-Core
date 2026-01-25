<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyBookAccountMapping extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'version',
        'effective_from',
        'effective_to',
        'expense_debit_account_id',
        'expense_credit_account_id',
        'income_debit_account_id',
        'income_credit_account_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'created_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function expenseDebitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_debit_account_id');
    }

    public function expenseCreditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_credit_account_id');
    }

    public function incomeDebitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_debit_account_id');
    }

    public function incomeCreditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_credit_account_id');
    }
}
