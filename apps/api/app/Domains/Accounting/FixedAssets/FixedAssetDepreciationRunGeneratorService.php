<?php

namespace App\Domains\Accounting\FixedAssets;

use App\Support\TenantScoped;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a DRAFT depreciation run and lines for ACTIVE assets (no ledger impact).
 */
class FixedAssetDepreciationRunGeneratorService
{
    public function generate(string $tenantId, string $periodStart, string $periodEnd): FixedAssetDepreciationRun
    {
        $ps = Carbon::parse($periodStart)->format('Y-m-d');
        $pe = Carbon::parse($periodEnd)->format('Y-m-d');
        if ($ps > $pe) {
            throw ValidationException::withMessages([
                'period_end' => ['period_end must be on or after period_start.'],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $ps, $pe) {
            $referenceNo = $this->nextReferenceNo($tenantId);

            $run = FixedAssetDepreciationRun::create([
                'tenant_id' => $tenantId,
                'reference_no' => $referenceNo,
                'status' => FixedAssetDepreciationRun::STATUS_DRAFT,
                'period_start' => $ps,
                'period_end' => $pe,
            ]);

            $assets = TenantScoped::for(FixedAsset::query(), $tenantId)
                ->where('status', FixedAsset::STATUS_ACTIVE)
                ->whereNotNull('in_service_date')
                ->where('in_service_date', '<=', $pe)
                ->where('depreciation_method', FixedAsset::DEPRECIATION_STRAIGHT_LINE)
                ->orderBy('asset_code')
                ->get();

            foreach ($assets as $asset) {
                if ($this->assetHasPostedDepreciationForOverlappingPeriod($tenantId, $asset->id, $ps, $pe)) {
                    continue;
                }

                $book = FixedAssetBook::query()
                    ->where('tenant_id', $tenantId)
                    ->where('fixed_asset_id', $asset->id)
                    ->where('book_type', FixedAssetBook::BOOK_PRIMARY)
                    ->first();

                if (! $book) {
                    continue;
                }

                $calc = FixedAssetStraightLineDepreciationCalculator::computePeriodDepreciation($asset, $book, $ps, $pe);
                if ($calc['amount'] <= 0) {
                    continue;
                }

                $opening = round((float) $calc['opening_carrying'], 2);
                $amount = round((float) $calc['amount'], 2);
                $closing = round(max(0.0, $opening - $amount), 2);

                FixedAssetDepreciationLine::create([
                    'tenant_id' => $tenantId,
                    'depreciation_run_id' => $run->id,
                    'fixed_asset_id' => $asset->id,
                    'depreciation_amount' => $amount,
                    'opening_carrying_amount' => $opening,
                    'closing_carrying_amount' => $closing,
                    'depreciation_start' => $calc['depreciation_start'],
                    'depreciation_end' => $calc['depreciation_end'],
                ]);
            }

            return $run->fresh(['lines.fixedAsset']);
        });
    }

    private function nextReferenceNo(string $tenantId): string
    {
        for ($i = 0; $i < 10; $i++) {
            $candidate = 'DEP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
            $exists = FixedAssetDepreciationRun::query()
                ->where('tenant_id', $tenantId)
                ->where('reference_no', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return 'DEP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    private function assetHasPostedDepreciationForOverlappingPeriod(
        string $tenantId,
        string $fixedAssetId,
        string $periodStart,
        string $periodEnd
    ): bool {
        return DB::table('fixed_asset_depreciation_lines as l')
            ->join('fixed_asset_depreciation_runs as r', 'r.id', '=', 'l.depreciation_run_id')
            ->where('l.tenant_id', $tenantId)
            ->where('l.fixed_asset_id', $fixedAssetId)
            ->where('r.status', FixedAssetDepreciationRun::STATUS_POSTED)
            ->where('r.period_start', '<=', $periodEnd)
            ->where('r.period_end', '>=', $periodStart)
            ->exists();
    }
}
