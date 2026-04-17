<?php

namespace App\Services;

use App\Domains\Reporting\ProjectPLQueryService;
use App\Models\PostingGroup;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — read-only responsibility / recoverability semantics for project-scoped economics.
 *
 * Does not post or alter ledger data. Derives buckets from allocation_rows using the same
 * operational posting-group filter as settlement preview when building settlement explanation;
 * period reports use the same posting-group eligibility as project P&amp;L.
 */
class ProjectResponsibilityReadService
{
    public function __construct(
        private ProjectPLQueryService $projectPLQueryService,
        private ProjectSettlementRuleResolver $settlementRuleResolver,
    ) {}

    /**
     * Farm-first explanation merged into project settlement preview (additive JSON only).
     *
     * @return array<string, mixed>
     */
    public function buildForSettlementPreview(string $projectId, string $tenantId, string $upToDate): array
    {
        $pgIds = $this->operationalPostingGroupIdsForProject($projectId, $tenantId, $upToDate, null);
        $breakdown = $this->aggregateAllocationResponsibility($tenantId, $projectId, $pgIds);

        return [
            'summary_lines' => [
                'Shared pool costs (used in settlement pool profit)' => round($breakdown['settlement_shared_pool_costs'], 2),
                'Hari-only costs (deducted from Hari share after split)' => round($breakdown['hari_only_costs'], 2),
                'Landlord / owner-only costs (not in shared pool)' => round($breakdown['landlord_only_costs'], 2),
            ],
            'recoverability' => [
                'included_in_shared_pool_for_settlement' => round($breakdown['settlement_shared_pool_costs'], 2),
                'hari_borne_after_split' => round($breakdown['hari_only_costs'], 2),
                'owner_borne_not_in_pool' => round($breakdown['landlord_only_costs'], 2),
                'shared_scope_other_amounts' => round($breakdown['shared_scope_non_pool_share_positive'], 2),
                'shared_scope_other_note' => $breakdown['shared_scope_non_pool_share_positive'] > 0.005
                    ? 'Other shared-scope charges (for example internal machinery work) appear in the ledger; settlement pool profit still subtracts only POOL_SHARE+SHARED rows, matching the existing settlement engine.'
                    : null,
            ],
            'legacy_unscoped_expense_allocation' => round($breakdown['legacy_unscoped_amount'], 2),
            'legacy_unscoped_note' => $breakdown['legacy_unscoped_amount'] > 0.005
                ? 'Some cost rows have no allocation_scope and could not be mapped from allocation_type; review source postings.'
                : null,
            'by_effective_responsibility' => $breakdown['by_effective_scope'],
            'top_allocation_types' => $breakdown['top_types'],
        ];
    }

    /**
     * Period view aligned with project P&amp;L eligibility (GET reports/project-responsibility).
     *
     * @return array<string, mixed>
     */
    public function summarizeForProjectPeriod(
        string $tenantId,
        string $projectId,
        string $from,
        string $to,
        ?string $cropCycleId = null
    ): array {
        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();

        $pgIds = $this->projectPLQueryService->getEligiblePostingGroupIdsForProject(
            $tenantId,
            $projectId,
            $from,
            $to,
            $cropCycleId
        );
        if ($pgIds === []) {
            return [
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
                'posting_groups_count' => 0,
                'buckets' => [],
                'by_effective_responsibility' => [],
                'top_allocation_types' => [],
                'settlement_terms' => $this->settlementTermsSummary($project),
            ];
        }

        $breakdown = $this->aggregateAllocationResponsibility($tenantId, $projectId, collect($pgIds));

        return [
            'project_id' => $projectId,
            'from' => $from,
            'to' => $to,
            'crop_cycle_id' => $cropCycleId,
            'posting_groups_count' => count($pgIds),
            'buckets' => [
                'settlement_shared_pool_costs' => round($breakdown['settlement_shared_pool_costs'], 2),
                'hari_only_costs' => round($breakdown['hari_only_costs'], 2),
                'landlord_only_costs' => round($breakdown['landlord_only_costs'], 2),
                'shared_scope_non_pool_share_positive' => round($breakdown['shared_scope_non_pool_share_positive'], 2),
                'legacy_unscoped_amount' => round($breakdown['legacy_unscoped_amount'], 2),
            ],
            'by_effective_responsibility' => $breakdown['by_effective_scope'],
            'top_allocation_types' => $breakdown['top_types'],
            'settlement_terms' => $this->settlementTermsSummary($project),
        ];
    }

    /**
     * Party-facing read model for the project Hari party (or read-only context for others).
     *
     * @param  array<string, mixed>  $settlementPreview
     * @return array<string, mixed>
     */
    public function partyEconomicsReadModel(
        string $tenantId,
        string $projectId,
        string $partyId,
        string $upToDate,
        array $settlementPreview
    ): array {
        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();
        $hariPartyId = $project->party_id;
        $isProjectHari = $hariPartyId === $partyId;

        $explanation = $this->buildForSettlementPreview($projectId, $tenantId, $upToDate);

        $out = [
            'project_id' => $projectId,
            'party_id' => $partyId,
            'up_to_date' => $upToDate,
            'is_project_hari_party' => $isProjectHari,
            'party_economics_explanation' => $explanation,
            'settlement_terms' => $this->settlementTermsSummary($project),
        ];

        if ($isProjectHari) {
            $out['hari_settlement_preview'] = [
                'hari_gross' => $settlementPreview['hari_gross'] ?? null,
                'hari_only_deductions' => $settlementPreview['hari_only_deductions'] ?? null,
                'hari_net' => $settlementPreview['hari_net'] ?? null,
                'hari_position' => $settlementPreview['hari_position'] ?? null,
                'kamdari_amount' => $settlementPreview['kamdari_amount'] ?? null,
                'landlord_gross' => $settlementPreview['landlord_gross'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, string>|array<int, string>  $postingGroupIds
     * @return array{
     *   settlement_shared_pool_costs: float,
     *   hari_only_costs: float,
     *   landlord_only_costs: float,
     *   shared_scope_non_pool_share_positive: float,
     *   legacy_unscoped_amount: float,
     *   by_effective_scope: array<string, float>,
     *   top_types: array<int, array{type: string, amount: float}>
     * }
     */
    private function aggregateAllocationResponsibility(string $tenantId, string $projectId, Collection|array $postingGroupIds): array
    {
        $ids = $postingGroupIds instanceof Collection ? $postingGroupIds->all() : $postingGroupIds;
        if ($ids === []) {
            return [
                'settlement_shared_pool_costs' => 0.0,
                'hari_only_costs' => 0.0,
                'landlord_only_costs' => 0.0,
                'shared_scope_non_pool_share_positive' => 0.0,
                'legacy_unscoped_amount' => 0.0,
                'by_effective_scope' => [],
                'top_types' => [],
            ];
        }

        $scopeSums = DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('posting_group_id', $ids)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN allocation_scope = 'SHARED' AND allocation_type::text = 'POOL_SHARE' THEN COALESCE(amount, 0) ELSE 0 END), 0) AS settlement_shared,
                COALESCE(SUM(CASE WHEN allocation_scope = 'HARI_ONLY' THEN COALESCE(amount, 0) ELSE 0 END), 0) AS hari_only,
                COALESCE(SUM(CASE WHEN allocation_scope = 'LANDLORD_ONLY' THEN COALESCE(amount, 0) ELSE 0 END), 0) AS landlord_only,
                COALESCE(SUM(CASE
                    WHEN allocation_scope = 'SHARED'
                        AND allocation_type::text <> 'POOL_SHARE'
                        AND allocation_type::text NOT IN ('POOL_REVENUE','SALE_REVENUE','MACHINERY_EXTERNAL_INCOME')
                        AND COALESCE(amount, 0) > 0
                    THEN amount ELSE 0 END), 0) AS shared_other
            ")
            ->first();

        $effExpr = "COALESCE(allocation_rows.allocation_scope::text, CASE allocation_rows.allocation_type::text WHEN 'POOL_SHARE' THEN 'SHARED' WHEN 'POOL_REVENUE' THEN 'SHARED' WHEN 'HARI_ONLY' THEN 'HARI_ONLY' WHEN 'LANDLORD_ONLY' THEN 'LANDLORD_ONLY' ELSE 'UNSPECIFIED' END)";

        $byScopeRaw = DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('posting_group_id', $ids)
            ->whereRaw('COALESCE(amount, 0) <> 0')
            ->whereRaw("allocation_rows.allocation_type::text NOT IN ('POOL_REVENUE','SALE_REVENUE','MACHINERY_EXTERNAL_INCOME','ADVANCE_OFFSET','KAMDARI')")
            ->selectRaw("{$effExpr} AS eff_scope, SUM(COALESCE(amount, 0)) AS amt")
            ->groupBy(DB::raw($effExpr))
            ->get()
            ->sortByDesc(fn ($r) => abs((float) $r->amt))
            ->values();

        $byScope = [];
        foreach ($byScopeRaw as $r) {
            $byScope[(string) $r->eff_scope] = round((float) $r->amt, 2);
        }

        $legacyUnscoped = (float) DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('posting_group_id', $ids)
            ->whereNull('allocation_scope')
            ->whereRaw('COALESCE(amount, 0) > 0')
            ->whereRaw("allocation_type::text NOT IN ('POOL_REVENUE','SALE_REVENUE','MACHINERY_EXTERNAL_INCOME')")
            ->whereRaw("allocation_type::text NOT IN ('POOL_SHARE','HARI_ONLY','LANDLORD_ONLY')")
            ->sum(DB::raw('COALESCE(amount, 0)'));

        $topTypesRaw = DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('posting_group_id', $ids)
            ->whereRaw('COALESCE(amount, 0) <> 0')
            ->selectRaw('allocation_type::text AS t, SUM(COALESCE(amount, 0)) AS amt')
            ->groupByRaw('allocation_type')
            ->get();

        $topTypes = $topTypesRaw
            ->sortByDesc(fn ($r) => abs((float) $r->amt))
            ->take(12)
            ->values()
            ->map(fn ($r) => ['type' => (string) $r->t, 'amount' => round((float) $r->amt, 2)])
            ->all();

        return [
            'settlement_shared_pool_costs' => (float) ($scopeSums->settlement_shared ?? 0),
            'hari_only_costs' => (float) ($scopeSums->hari_only ?? 0),
            'landlord_only_costs' => (float) ($scopeSums->landlord_only ?? 0),
            'shared_scope_non_pool_share_positive' => (float) ($scopeSums->shared_other ?? 0),
            'legacy_unscoped_amount' => $legacyUnscoped,
            'by_effective_scope' => $byScope,
            'top_types' => $topTypes,
        ];
    }

    private function operationalPostingGroupIdsForProject(string $projectId, string $tenantId, string $upToDate, ?string $fromDate): Collection
    {
        $q = PostingGroup::where('tenant_id', $tenantId)
            ->where('posting_date', '<=', $upToDate)
            ->whereIn('source_type', SettlementService::operationalPostingSourceTypes())
            ->whereExists(function ($sub) use ($projectId) {
                $sub->select(DB::raw(1))
                    ->from('allocation_rows')
                    ->whereColumn('allocation_rows.posting_group_id', 'posting_groups.id')
                    ->where('allocation_rows.project_id', $projectId);
            });

        if ($fromDate !== null && $fromDate !== '') {
            $q->where('posting_date', '>=', $fromDate);
        }

        return $q->pluck('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementTermsSummary(Project $project): array
    {
        try {
            $r = $this->settlementRuleResolver->resolveSettlementRule($project);

            return [
                'resolution_source' => $r['resolution_source'],
                'agreement_id' => $r['agreement_id'],
                'project_rule_id' => $r['project_rule_id'],
                'profit_split_landlord_pct' => $r['profit_split_landlord_pct'],
                'profit_split_hari_pct' => $r['profit_split_hari_pct'],
                'kamdari_pct' => $r['kamdari_pct'],
                'kamdari_order' => $r['kamdari_order'],
                'pool_definition' => $r['pool_definition'],
            ];
        } catch (\Throwable $e) {
            return [
                'resolution_source' => null,
                'resolution_error' => $e->getMessage(),
            ];
        }
    }
}
