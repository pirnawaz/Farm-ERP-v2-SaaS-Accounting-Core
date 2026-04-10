<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared harvest share bucket math for preview and posting (Phase 3A.2 / 3C).
 *
 * @phpstan-import-type BucketArray from self
 */
class HarvestShareBucketService
{
    public const QTY_DECIMALS = 3;

    public const VALUE_DECIMALS = 2;

    private const QTY_EPS = 0.0005;

    public function __construct(
        private SystemAccountService $accountService
    ) {}

    /**
     * Build buckets: same logic as draft preview; used by {@see HarvestSharePreviewService}
     * and {@see HarvestService::post} so quantities/values cannot drift.
     *
     * @return array{
     *   buckets: Collection<int, array<string, mixed>>,
     *   total_wip_cost: float,
     *   allocated_costs: array<int, float>,
     *   warnings: array<int, string>,
     *   harvest_line_payload: array<int, array<string, mixed>>,
     *   posting_date_used: string,
     *   has_aggregate_scope: bool,
     *   lines: Collection<int, HarvestLine>
     * }
     */
    public function compute(Harvest $harvest, ?string $postingDate = null): array
    {
        $harvest->loadMissing(['lines', 'shareLines', 'cropCycle']);

        if ($harvest->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => ['Harvest must have at least one line.'],
            ]);
        }

        foreach ($harvest->lines as $line) {
            if ((float) $line->quantity <= 0) {
                throw ValidationException::withMessages([
                    'lines' => ['All harvest line quantities must be greater than zero.'],
                ]);
            }
        }

        $postingDateNorm = $this->normalizePostingDate($harvest, $postingDate);
        $totalWipCost = $this->calculateWipCost($harvest->tenant_id, $harvest->crop_cycle_id, $postingDateNorm);

        $lines = $harvest->lines->values();
        $allocatedCosts = $this->allocateCost($totalWipCost, $lines);

        $shareLines = $harvest->shareLines->sortBy([
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->values();

        $warnings = [];
        if ($totalWipCost <= 0.00001) {
            $warnings[] = 'Net CROP_WIP for this crop cycle up to the posting date is zero; provisional values will be zero.';
        }

        $harvestLinePayload = [];
        foreach ($lines as $index => $line) {
            $q = (float) $line->quantity;
            $c = (float) ($allocatedCosts[$index] ?? 0);
            $harvestLinePayload[] = [
                'harvest_line_id' => $line->id,
                'quantity' => round($q, self::QTY_DECIMALS),
                'allocated_wip_cost' => round($c, self::VALUE_DECIMALS),
                'provisional_unit_cost' => $q > 0 ? round($c / $q, 6) : 0.0,
            ];
        }

        if ($shareLines->isEmpty()) {
            $buckets = $this->implicitOwnerOnlyBuckets($lines, $allocatedCosts);
            $hasAggregateScope = false;
        } else {
            $hasNullScope = $shareLines->contains(fn (HarvestShareLine $s) => $s->harvest_line_id === null);
            $hasLineScope = $shareLines->contains(fn (HarvestShareLine $s) => $s->harvest_line_id !== null);

            if ($hasNullScope && $hasLineScope) {
                throw ValidationException::withMessages([
                    'share_lines' => ['Cannot mix harvest-level share lines (no harvest_line_id) with line-scoped share lines.'],
                ]);
            }

            $hasAggregateScope = $hasNullScope;

            if ($hasNullScope) {
                $buckets = $this->previewAggregateScope(
                    $shareLines,
                    $lines,
                    $totalWipCost,
                    $warnings
                );
            } else {
                $buckets = $this->previewLineScoped(
                    $shareLines,
                    $lines,
                    $allocatedCosts,
                    $warnings
                );
            }
        }

        return [
            'buckets' => $buckets,
            'total_wip_cost' => $totalWipCost,
            'allocated_costs' => $allocatedCosts,
            'warnings' => $warnings,
            'harvest_line_payload' => $harvestLinePayload,
            'posting_date_used' => $postingDateNorm,
            'has_aggregate_scope' => $hasAggregateScope,
            'lines' => $lines,
        ];
    }

    /**
     * Same algorithm as {@see HarvestService::allocateCost()} (kept in sync for posting).
     *
     * @param  Collection<int, HarvestLine>  $lines
     * @return array<int, float>
     */
    public function allocateCost(float $totalCost, Collection $lines): array
    {
        $allocated = [];
        $totalQty = (float) $lines->sum(fn ($l) => (float) $l->quantity);

        if ($totalQty > 0) {
            $allocatedTotal = 0.0;
            $lineCount = $lines->count();
            foreach ($lines as $index => $line) {
                if ($index === $lineCount - 1) {
                    $allocated[$index] = $totalCost - $allocatedTotal;
                } else {
                    $lineAllocation = $totalCost * ((float) $line->quantity / $totalQty);
                    $allocated[$index] = round($lineAllocation, self::VALUE_DECIMALS);
                    $allocatedTotal += $allocated[$index];
                }
            }
        } else {
            $perLine = $totalCost / $lines->count();
            $allocatedTotal = 0.0;
            $lineCount = $lines->count();
            foreach ($lines as $index => $line) {
                if ($index === $lineCount - 1) {
                    $allocated[$index] = $totalCost - $allocatedTotal;
                } else {
                    $allocated[$index] = round($perLine, self::VALUE_DECIMALS);
                    $allocatedTotal += $allocated[$index];
                }
            }
        }

        return $allocated;
    }

    /**
     * Net CROP_WIP up to posting date (same as harvest post).
     */
    public function calculateWipCost(string $tenantId, string $cropCycleId, string $postingDate): float
    {
        $cropWipAccount = $this->accountService->getByCode($tenantId, 'CROP_WIP');

        $netBalance = DB::table('ledger_entries')
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('posting_groups.crop_cycle_id', $cropCycleId)
            ->where('posting_groups.posting_date', '<=', $postingDate)
            ->where('ledger_entries.account_id', $cropWipAccount->id)
            ->selectRaw('SUM(ledger_entries.debit_amount - ledger_entries.credit_amount) as net')
            ->value('net');

        return max(0, (float) ($netBalance ?? 0));
    }

    /**
     * @param  array<int, float>  $allocatedCosts
     * @return Collection<int, array<string, mixed>>
     */
    private function implicitOwnerOnlyBuckets(Collection $lines, array $allocatedCosts): Collection
    {
        $buckets = collect();
        foreach ($lines as $index => $line) {
            $q = (float) $line->quantity;
            $c = (float) ($allocatedCosts[$index] ?? 0);
            $uc = $q > 0 ? $c / $q : 0.0;
            $buckets->push([
                'share_line_id' => null,
                'harvest_line_id' => $line->id,
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => null,
                'implicit_owner' => true,
                'computed_qty' => round($q, self::QTY_DECIMALS),
                'provisional_unit_cost' => round($uc, 6),
                'provisional_value' => round($c, self::VALUE_DECIMALS),
            ]);
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, HarvestShareLine>  $shareLines
     * @param  Collection<int, HarvestLine>  $lines
     * @param  array<int, float>  $allocatedCosts
     * @return Collection<int, array<string, mixed>>
     */
    private function previewLineScoped(
        Collection $shareLines,
        Collection $lines,
        array $allocatedCosts,
        array &$warnings
    ): Collection {
        $byLineId = $shareLines->groupBy('harvest_line_id');
        $out = collect();

        foreach ($lines as $index => $line) {
            $subs = $byLineId->get($line->id, collect());
            if ($subs->isEmpty()) {
                $q = (float) $line->quantity;
                $c = (float) ($allocatedCosts[$index] ?? 0);
                $uc = $q > 0 ? $c / $q : 0.0;
                $out->push([
                    'share_line_id' => null,
                    'harvest_line_id' => $line->id,
                    'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                    'settlement_mode' => null,
                    'implicit_owner' => true,
                    'computed_qty' => round($q, self::QTY_DECIMALS),
                    'provisional_unit_cost' => round($uc, 6),
                    'provisional_value' => round($c, self::VALUE_DECIMALS),
                ]);
                $warnings[] = 'Harvest line '.$line->id.' has no share lines; assuming 100% owner retained for that line.';

                continue;
            }

            $c = (float) ($allocatedCosts[$index] ?? 0);
            $out = $out->merge($this->computeBucketsForPhysicalLine($line, $subs, $c, false, $warnings));
        }

        return $out;
    }

    /**
     * @param  Collection<int, HarvestShareLine>  $shareLines
     * @param  Collection<int, HarvestLine>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function previewAggregateScope(
        Collection $shareLines,
        Collection $lines,
        float $totalWipCost,
        array &$warnings
    ): Collection {
        $qTotal = (float) $lines->sum(fn ($l) => (float) $l->quantity);

        return $this->computeBucketsForPhysicalLine(
            null,
            $shareLines,
            $totalWipCost,
            true,
            $warnings,
            $qTotal
        );
    }

    /**
     * @param  Collection<int, HarvestShareLine>  $subs
     * @return Collection<int, array<string, mixed>>
     */
    private function computeBucketsForPhysicalLine(
        ?HarvestLine $line,
        Collection $subs,
        float $allocatedCost,
        bool $aggregate,
        array &$warnings,
        ?float $quantityOverride = null
    ): Collection {
        $Q = $quantityOverride ?? (float) ($line?->quantity ?? 0);
        if ($Q <= 0) {
            throw ValidationException::withMessages([
                'share_lines' => ['Aggregate harvest quantity must be greater than zero.'],
            ]);
        }

        $this->validateDestinationContext($subs, $warnings);

        $remainderLines = $subs->filter(fn (HarvestShareLine $s) => $this->isRemainderLine($s));
        if ($remainderLines->count() > 1) {
            throw ValidationException::withMessages([
                'share_lines' => ['At most one remainder bucket is allowed per harvest line scope.'],
            ]);
        }

        $nonRem = $subs->filter(fn (HarvestShareLine $s) => ! $this->isRemainderLine($s))->values();
        $rem = $remainderLines->first();

        $explicitQty = 0.0;
        $qtyById = [];

        foreach ($nonRem as $s) {
            $q = $this->computeNonRemainderQty($Q, $s);
            $qtyById[$s->id] = $q;
            $explicitQty += $q;
        }

        if ($explicitQty > $Q + self::QTY_EPS) {
            throw ValidationException::withMessages([
                'share_lines' => ['Over-allocation: explicit bucket quantities exceed the line quantity.'],
            ]);
        }

        if ($rem !== null) {
            $remQty = $Q - $explicitQty;
            if ($remQty < -self::QTY_EPS) {
                throw ValidationException::withMessages([
                    'share_lines' => ['Remainder bucket would be negative; reduce explicit shares.'],
                ]);
            }
            $qtyById[$rem->id] = round($remQty, self::QTY_DECIMALS);
        } else {
            $implicit = $Q - $explicitQty;
            if ($implicit < -self::QTY_EPS) {
                throw ValidationException::withMessages([
                    'share_lines' => ['Over-allocation: total explicit shares exceed the line quantity.'],
                ]);
            }
        }

        $unitCost = $allocatedCost / $Q;

        $ordered = collect();
        foreach ($subs->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $s) {
            if (! isset($qtyById[$s->id])) {
                continue;
            }
            $ordered->push(['line' => $s, 'qty' => (float) $qtyById[$s->id], 'implicit' => false]);
        }

        if ($rem === null) {
            $implicit = round($Q - $explicitQty, self::QTY_DECIMALS);
            if ($implicit > self::QTY_EPS) {
                $ordered->push([
                    'line' => null,
                    'qty' => $implicit,
                    'implicit' => true,
                ]);
            }
        }

        if ($ordered->isEmpty()) {
            throw ValidationException::withMessages([
                'share_lines' => ['No computable share buckets for this line.'],
            ]);
        }

        $values = $this->allocateValuesLastBucketAbsorbsCents($unitCost, $allocatedCost, $ordered);

        $out = collect();
        $i = 0;
        foreach ($ordered as $row) {
            $qty = $row['qty'];
            $val = $values[$i++];
            $sl = $row['line'];
            if ($row['implicit']) {
                $out->push([
                    'share_line_id' => null,
                    'harvest_line_id' => $aggregate ? null : $line?->id,
                    'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                    'settlement_mode' => null,
                    'implicit_owner' => true,
                    'computed_qty' => round($qty, self::QTY_DECIMALS),
                    'provisional_unit_cost' => round($unitCost, 6),
                    'provisional_value' => round($val, self::VALUE_DECIMALS),
                ]);
            } else {
                $out->push([
                    'share_line_id' => $sl->id,
                    'harvest_line_id' => $aggregate ? null : $line?->id,
                    'recipient_role' => $sl->recipient_role,
                    'settlement_mode' => $sl->settlement_mode,
                    'implicit_owner' => false,
                    'computed_qty' => round($qty, self::QTY_DECIMALS),
                    'provisional_unit_cost' => round($unitCost, 6),
                    'provisional_value' => round($val, self::VALUE_DECIMALS),
                ]);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, HarvestShareLine>  $subs
     */
    private function validateDestinationContext(Collection $subs, array &$warnings): void
    {
        foreach ($subs as $s) {
            if ($s->settlement_mode === HarvestShareLine::SETTLEMENT_IN_KIND
                && $s->store_id === null) {
                throw ValidationException::withMessages([
                    'share_lines' => ['In-kind share lines require a store_id for inventory destination (share line '.$s->id.').'],
                ]);
            }
        }
    }

    private function isRemainderLine(HarvestShareLine $s): bool
    {
        return $s->remainder_bucket
            || $s->share_basis === HarvestShareLine::BASIS_REMAINDER;
    }

    private function computeNonRemainderQty(float $Q, HarvestShareLine $s): float
    {
        return match ($s->share_basis) {
            HarvestShareLine::BASIS_FIXED_QTY => round(
                min((float) $s->share_value, $Q),
                self::QTY_DECIMALS
            ),
            HarvestShareLine::BASIS_PERCENT => $this->roundQty($Q * (float) $s->share_value / 100.0),
            HarvestShareLine::BASIS_RATIO => $this->ratioQty($Q, $s),
            default => throw ValidationException::withMessages([
                'share_lines' => ['Unsupported share_basis for non-remainder bucket: '.$s->share_basis],
            ]),
        };
    }

    private function ratioQty(float $Q, HarvestShareLine $s): float
    {
        $n = (float) $s->ratio_numerator;
        $d = (float) $s->ratio_denominator;
        if ($n <= 0 || $d <= 0) {
            throw ValidationException::withMessages([
                'share_lines' => ['Invalid ratio for share line '.$s->id.'.'],
            ]);
        }

        return $this->roundQty($Q * $n / ($n + $d));
    }

    private function roundQty(float $x): float
    {
        return round($x, self::QTY_DECIMALS);
    }

    /**
     * @param  Collection<int, array{line: HarvestShareLine|null, qty: float, implicit: bool}>  $ordered
     * @return array<int, float>
     */
    private function allocateValuesLastBucketAbsorbsCents(float $unitCost, float $allocatedCost, Collection $ordered): array
    {
        $n = $ordered->count();
        $values = [];
        $sumPrev = 0.0;
        $i = 0;
        foreach ($ordered as $row) {
            $qty = $row['qty'];
            if ($i < $n - 1) {
                $v = round($unitCost * $qty, self::VALUE_DECIMALS);
                $values[] = $v;
                $sumPrev += $v;
            } else {
                $values[] = round($allocatedCost - $sumPrev, self::VALUE_DECIMALS);
            }
            $i++;
        }

        return $values;
    }

    private function normalizePostingDate(Harvest $harvest, ?string $postingDate): string
    {
        if ($postingDate !== null && $postingDate !== '') {
            return Carbon::parse($postingDate)->format('Y-m-d');
        }
        if ($harvest->harvest_date) {
            return $harvest->harvest_date->format('Y-m-d');
        }

        return now()->format('Y-m-d');
    }
}
