<?php

namespace App\Domains\Accounting\MultiCurrency;

use Illuminate\Validation\ValidationException;

/**
 * Resolves FX for a posting date and document currency; fails with 422 when a foreign rate is missing.
 */
final class PostingFxService
{
    public function __construct(private FxRateResolver $fxRateResolver) {}

    public function forPosting(string $tenantId, string $postingDateYmd, string $transactionCurrencyCode): PostingFxSnapshot
    {
        $base = strtoupper((string) ($this->fxRateResolver->tenantBaseCurrencyCode($tenantId) ?? 'GBP'));
        $tx = strtoupper(trim($transactionCurrencyCode));
        if ($tx === '') {
            $tx = $base;
        }

        if ($base === $tx) {
            return new PostingFxSnapshot($base, $tx, '1');
        }

        $rate = $this->fxRateResolver->rateForPostingDate($tenantId, $postingDateYmd, $base, $tx);
        if ($rate === null) {
            throw ValidationException::withMessages([
                'exchange_rate' => [
                    "No exchange rate for {$tx} against functional currency {$base} on or before {$postingDateYmd}. Add a rate (base per 1 {$tx}) before posting.",
                ],
            ]);
        }

        return new PostingFxSnapshot($base, $tx, (string) $rate);
    }
}
