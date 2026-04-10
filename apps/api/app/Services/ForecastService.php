<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPlan;

/**
 * Read-only forecast vs actual for a project (Phase 8C.1).
 *
 * Planned figures come from {@see ProjectPlan} / {@see ProjectPlanCost} / {@see ProjectPlanYield}.
 * Actuals are delegated to {@see ProjectProfitabilityService} only — no posting or ledger logic here.
 *
 * Pre-harvest projection ({@see getProjectedProfitability}): planned yield value vs costs posted to date (see method doc).
 */
class ForecastService
{
    public function __construct(
        private ProjectProfitabilityService $projectProfitabilityService
    ) {}

    /**
     * Compare planned (latest active plan, else latest plan) to posted profitability for the same project scope.
     *
     * Planned revenue: sum over yield rows of `expected_quantity * expected_unit_value` when both are set; otherwise 0 for that row.
     * Planned cost: sum of `expected_cost` on cost rows (null treated as 0).
     * Planned profit: planned revenue − planned cost.
     *
     * When {@code $actualFilters} omits `crop_cycle_id`, it defaults to the selected plan’s `crop_cycle_id` so actuals align with the plan scope.
     * Pass explicit `from` / `to` to limit actuals by posting date (same semantics as profitability).
     *
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $actualFilters
     * @return array{
     *   planned: array{cost: float, revenue: float, profit: float},
     *   actual: array{cost: float, revenue: float, profit: float},
     *   variance: array{cost: float, revenue: float, profit: float}
     * }
     * Variance is actual − planned (positive revenue variance = ahead of plan; positive cost variance = spend over plan).
     */
    public function getProjectForecast(string $projectId, array $actualFilters = []): array
    {
        $project = Project::query()->findOrFail($projectId);
        $tenantId = (string) $project->tenant_id;

        $plan = $this->resolvePlanForProject($tenantId, $projectId);
        $planned = $this->plannedTotals($plan);

        $profitabilityFilters = $this->mergeActualFiltersWithPlan($plan, $actualFilters);
        $report = $this->projectProfitabilityService->getProjectProfitability($projectId, $tenantId, $profitabilityFilters);

        $actual = [
            'cost' => (float) $report['totals']['cost'],
            'revenue' => (float) $report['totals']['revenue'],
            'profit' => (float) $report['totals']['profit'],
        ];

        return [
            'planned' => $planned,
            'actual' => $actual,
            'variance' => [
                'cost' => round($actual['cost'] - $planned['cost'], 2),
                'revenue' => round($actual['revenue'] - $planned['revenue'], 2),
                'profit' => round($actual['profit'] - $planned['profit'], 2),
            ],
        ];
    }

    /**
     * Pre-harvest snapshot: “If we valued the crop at planned yield today, vs money already spent on this project scope.”
     *
     * - **projected_revenue:** Sum of `expected_quantity × expected_unit_value` from the resolved plan’s yield rows (same rules as forecast planned revenue).
     * - **projected_cost:** Posted cost to date from {@see ProjectProfitabilityService::getProjectProfitability} (ledger-backed actuals for the slice).
     * - **projected_profit:** projected_revenue − projected_cost.
     *
     * Defaults `to` (posting date) to **today** when omitted so “costs so far” does not include future-dated postings. Override `from` / `to` / `crop_cycle_id` via {@code $actualFilters} when needed.
     *
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $actualFilters
     * @return array{projected_revenue: float, projected_cost: float, projected_profit: float}
     */
    public function getProjectedProfitability(string $projectId, array $actualFilters = []): array
    {
        $project = Project::query()->findOrFail($projectId);
        $tenantId = (string) $project->tenant_id;

        $plan = $this->resolvePlanForProject($tenantId, $projectId);
        $projectedRevenue = $this->plannedYieldRevenueFromPlan($plan);

        $profitabilityFilters = $this->mergeProjectedProfitabilityFilters($plan, $actualFilters);
        $report = $this->projectProfitabilityService->getProjectProfitability($projectId, $tenantId, $profitabilityFilters);
        $projectedCost = round((float) $report['totals']['cost'], 2);

        $projectedProfit = round($projectedRevenue - $projectedCost, 2);

        return [
            'projected_revenue' => $projectedRevenue,
            'projected_cost' => $projectedCost,
            'projected_profit' => $projectedProfit,
        ];
    }

    private function resolvePlanForProject(string $tenantId, string $projectId): ?ProjectPlan
    {
        $active = ProjectPlan::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('status', ProjectPlan::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->first();

        if ($active !== null) {
            return $active;
        }

        return ProjectPlan::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return array{cost: float, revenue: float, profit: float}
     */
    private function plannedTotals(?ProjectPlan $plan): array
    {
        if ($plan === null) {
            return [
                'cost' => 0.0,
                'revenue' => 0.0,
                'profit' => 0.0,
            ];
        }

        $plan->load(['costs', 'yields']);

        $cost = 0.0;
        foreach ($plan->costs as $row) {
            $cost += (float) ($row->expected_cost ?? 0);
        }
        $cost = round($cost, 2);

        $revenue = $this->plannedYieldRevenueFromPlan($plan);

        $profit = round($revenue - $cost, 2);

        return [
            'cost' => $cost,
            'revenue' => $revenue,
            'profit' => $profit,
        ];
    }

    /**
     * Sum of planned harvest value from yield rows (quantity × unit value when both present).
     */
    private function plannedYieldRevenueFromPlan(?ProjectPlan $plan): float
    {
        if ($plan === null) {
            return 0.0;
        }

        $plan->loadMissing('yields');

        $revenue = 0.0;
        foreach ($plan->yields as $row) {
            $q = $row->expected_quantity;
            $unit = $row->expected_unit_value;
            if ($q !== null && $unit !== null) {
                $revenue += (float) $q * (float) $unit;
            }
        }

        return round($revenue, 2);
    }

    /**
     * For pre-harvest projection: align crop cycle with plan and default posting_date upper bound to today.
     *
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $actualFilters
     * @return array{from?: string, to?: string, crop_cycle_id?: string|null}
     */
    private function mergeProjectedProfitabilityFilters(?ProjectPlan $plan, array $actualFilters): array
    {
        $merged = $this->mergeActualFiltersWithPlan($plan, $actualFilters);
        if (! array_key_exists('to', $merged) || $merged['to'] === null || $merged['to'] === '') {
            $merged['to'] = now()->toDateString();
        }

        return $merged;
    }

    /**
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $actualFilters
     * @return array{from?: string, to?: string, crop_cycle_id?: string|null}
     */
    private function mergeActualFiltersWithPlan(?ProjectPlan $plan, array $actualFilters): array
    {
        if ($plan === null) {
            return $actualFilters;
        }

        $merged = $actualFilters;
        if (! array_key_exists('crop_cycle_id', $merged)) {
            $merged['crop_cycle_id'] = $plan->crop_cycle_id;
        }

        return $merged;
    }
}
