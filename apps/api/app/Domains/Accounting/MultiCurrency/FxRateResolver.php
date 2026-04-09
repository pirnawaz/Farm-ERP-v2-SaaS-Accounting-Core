<?php

namespace App\Domains\Accounting\MultiCurrency;

use App\Models\Tenant;
use Carbon\CarbonInterface;

/**
 * Deterministic FX resolution by posting date: latest stored rate with rate_date on or before the posting date.
 */
final class FxRateResolver
{
    /**
     * @return numeric-string|null Rate (base units per 1 quote unit), or null if no row exists.
     */
    public function rateForPostingDate(
        string $tenantId,
        CarbonInterface|string $postingDate,
        string $baseCurrencyCode,
        string $quoteCurrencyCode
    ): ?string {
        $base = strtoupper($baseCurrencyCode);
        $quote = strtoupper($quoteCurrencyCode);
        if ($base === $quote) {
            return '1';
        }

        $dateStr = $postingDate instanceof CarbonInterface
            ? $postingDate->format('Y-m-d')
            : (string) $postingDate;

        $row = ExchangeRate::query()
            ->where('tenant_id', $tenantId)
            ->where('base_currency_code', $base)
            ->where('quote_currency_code', $quote)
            ->whereDate('rate_date', '<=', $dateStr)
            ->orderByDesc('rate_date')
            ->first();

        return $row !== null ? (string) $row->rate : null;
    }

    /**
     * Functional (base) currency for the tenant — single reporting currency.
     */
    public function tenantBaseCurrencyCode(string $tenantId): ?string
    {
        $c = Tenant::query()->where('id', $tenantId)->value('currency_code');

        return $c !== null ? strtoupper((string) $c) : null;
    }
}
