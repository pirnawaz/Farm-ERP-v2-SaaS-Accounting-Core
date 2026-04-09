<?php

namespace App\Domains\Accounting\FixedAssets;

use Carbon\Carbon;

/**
 * Straight-line depreciation with calendar-month proration for partial first/last months.
 *
 * Monthly charge = (asset_cost - residual_value) / useful_life_months using book cost and asset residual.
 * Each calendar month in the effective window contributes (days_in_segment / days_in_month) * monthly_charge.
 */
final class FixedAssetStraightLineDepreciationCalculator
{
    /**
     * @return array{amount: float, depreciation_start: string, depreciation_end: string, opening_carrying: float}
     */
    public static function computePeriodDepreciation(
        FixedAsset $asset,
        FixedAssetBook $book,
        string $periodStartYmd,
        string $periodEndYmd
    ): array {
        $openingCarrying = round((float) $book->carrying_amount, 2);
        $residual = round((float) $asset->residual_value, 2);
        $remainingDepreciable = max(0.0, round($openingCarrying - $residual, 2));

        if ($remainingDepreciable <= 0) {
            return [
                'amount' => 0.0,
                'depreciation_start' => $periodStartYmd,
                'depreciation_end' => $periodEndYmd,
                'opening_carrying' => $openingCarrying,
            ];
        }

        if (! $asset->in_service_date) {
            return [
                'amount' => 0.0,
                'depreciation_start' => $periodStartYmd,
                'depreciation_end' => $periodEndYmd,
                'opening_carrying' => $openingCarrying,
            ];
        }

        $inService = Carbon::parse($asset->in_service_date)->startOfDay();
        $periodStart = Carbon::parse($periodStartYmd)->startOfDay();
        $periodEnd = Carbon::parse($periodEndYmd)->startOfDay();

        $lifeMonths = max(1, (int) $asset->useful_life_months);
        $assetCost = round((float) $book->asset_cost, 2);
        $totalDepreciable = max(0.0, round($assetCost - $residual, 2));
        $monthlyCharge = $totalDepreciable / $lifeMonths;

        $lifeEnd = $inService->copy()->addMonths($lifeMonths)->subDay();

        $effectiveStart = $periodStart->max($inService);
        $effectiveEnd = $periodEnd->min($lifeEnd);

        if ($effectiveStart->gt($effectiveEnd)) {
            return [
                'amount' => 0.0,
                'depreciation_start' => $effectiveStart->format('Y-m-d'),
                'depreciation_end' => $effectiveEnd->format('Y-m-d'),
                'opening_carrying' => $openingCarrying,
            ];
        }

        $rawCharge = 0.0;
        $cursor = $effectiveStart->copy()->startOfMonth();
        $lastMonthStart = $effectiveEnd->copy()->startOfMonth();

        while ($cursor->lte($lastMonthStart)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $daysInMonth = (int) $monthStart->daysInMonth;

            $segStart = $effectiveStart->max($monthStart);
            $segEnd = $effectiveEnd->min($monthEnd);
            if ($segStart->lte($segEnd)) {
                $daysSeg = (int) $segStart->diffInDays($segEnd) + 1;
                $rawCharge += ($daysSeg / $daysInMonth) * $monthlyCharge;
            }
            $cursor->addMonth();
        }

        $rawCharge = round($rawCharge, 2);
        $amount = min($rawCharge, $remainingDepreciable);

        return [
            'amount' => $amount,
            'depreciation_start' => $effectiveStart->format('Y-m-d'),
            'depreciation_end' => $effectiveEnd->format('Y-m-d'),
            'opening_carrying' => $openingCarrying,
        ];
    }
}
