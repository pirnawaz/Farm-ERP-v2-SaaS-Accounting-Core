<?php

namespace Tests\Unit;

use App\Domains\Accounting\MultiCurrency\FxRateResolver;
use PHPUnit\Framework\TestCase;

final class FxRateResolverUnitTest extends TestCase
{
    public function test_same_currency_pair_returns_one_without_hitting_database(): void
    {
        $resolver = new FxRateResolver;

        $this->assertSame('1', $resolver->rateForPostingDate('00000000-0000-0000-0000-000000000001', '2026-01-15', 'EUR', 'EUR'));
    }
}
