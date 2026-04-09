<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored FX quote: {@see $rate} is the number of **base** currency units per **one** unit of **quote** currency.
 * Example: base USD, quote EUR, rate 1.10 → 1 EUR = 1.10 USD (amount_usd = amount_eur * 1.10).
 *
 * Tenant functional currency is {@see Tenant::$currency_code}; stored rows use explicit base/quote pair for clarity.
 */
class ExchangeRate extends Model
{
    use HasUuids;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'tenant_id',
        'rate_date',
        'base_currency_code',
        'quote_currency_code',
        'rate',
        'source',
        'created_by',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'rate' => 'decimal:8',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
