<?php

namespace App\Domains\Accounting\MultiCurrency;

/**
 * FX snapshot for a posting: transaction (document) currency vs tenant functional (base) currency.
 * {@see FxRateResolver} defines rate as base units per 1 unit of transaction (quote) currency.
 */
final class PostingFxSnapshot
{
    public function __construct(
        public readonly string $baseCurrencyCode,
        public readonly string $transactionCurrencyCode,
        public readonly string $fxRate,
    ) {}

    public function amountInBase(float $transactionAmount): float
    {
        if (strtoupper($this->baseCurrencyCode) === strtoupper($this->transactionCurrencyCode)) {
            return round($transactionAmount, 2);
        }

        return round($transactionAmount * (float) $this->fxRate, 2);
    }
}
