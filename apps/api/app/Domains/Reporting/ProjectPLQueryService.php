<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Read-only project P&amp;L rows from posted ledger + project-scoped allocation rows (same semantics as legacy project-pl).
 *
 * Eligibility for which posting groups belong to a project: at least one allocation row with non-null
 * project_id for that posting group. Matches ProjectProfitabilityService (Phase 4.5) so management
 * profitability and project-pl reconcile.
 */
class ProjectPLQueryService
{
    /**
     * Posting groups whose ledger lines contribute to project P&amp;L for {@code $projectId} (same rule as project-pl).
     * Excludes cost-center-only postings (no project on allocation rows). Does not filter by source_type.
     *
     * @return list<string>
     */
    public function getEligiblePostingGroupIdsForProject(
        string $tenantId,
        string $projectId,
        ?string $from,
        ?string $to,
        ?string $cropCycleId = null
    ): array {
        $q = DB::table('posting_groups as pg')
            ->where('pg.tenant_id', $tenantId)
            ->whereExists(function ($sub) use ($tenantId, $projectId) {
                $sub->select(DB::raw(1))
                    ->from('allocation_rows as ar')
                    ->whereColumn('ar.posting_group_id', 'pg.id')
                    ->where('ar.tenant_id', $tenantId)
                    ->where('ar.project_id', $projectId);
            });

        if ($from !== null && $from !== '') {
            $q->where('pg.posting_date', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $q->where('pg.posting_date', '<=', $to);
        }

        if ($cropCycleId !== null && $cropCycleId !== '') {
            $q->whereExists(function ($sub) use ($tenantId, $projectId, $cropCycleId) {
                $sub->select(DB::raw(1))
                    ->from('projects as p')
                    ->where('p.tenant_id', $tenantId)
                    ->where('p.id', $projectId)
                    ->where('p.crop_cycle_id', $cropCycleId);
            });
        }

        return $q->orderBy('pg.id')->pluck('pg.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @return list<array{project_id: string, project_name: string|null, currency_code: string, income: string, expenses: string, net_profit: string}>
     */
    public function getProjectPlRows(
        string $tenantId,
        string $from,
        string $to,
        ?string $projectId = null,
        ?string $cropCycleId = null
    ): array {
        $query = "
            WITH project_allocations AS (
                SELECT DISTINCT posting_group_id, project_id
                FROM allocation_rows
                WHERE tenant_id = :tenant_id
                    AND project_id IS NOT NULL
            )
            SELECT
                pa.project_id,
                p.name AS project_name,
                le.currency_code,
                SUM(CASE WHEN a.type = 'income' THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount)) ELSE 0 END) AS income,
                SUM(CASE WHEN a.type = 'expense' THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount)) ELSE 0 END) AS expenses,
                SUM(
                    CASE
                        WHEN a.type = 'income' THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                        WHEN a.type = 'expense' THEN -(COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                        ELSE 0
                    END
                ) AS net_profit
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            JOIN project_allocations pa ON pa.posting_group_id = pg.id
            LEFT JOIN projects p ON p.id = pa.project_id AND p.tenant_id = le.tenant_id
            WHERE le.tenant_id = :tenant_id_b
                AND pg.posting_date BETWEEN :from AND :to
                AND a.type IN ('income', 'expense')
        ";

        $params = [
            'tenant_id' => $tenantId,
            'tenant_id_b' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];

        if ($projectId) {
            $query .= ' AND pa.project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        if ($cropCycleId) {
            $query .= ' AND p.crop_cycle_id = :crop_cycle_id';
            $params['crop_cycle_id'] = $cropCycleId;
        }

        $query .= ' GROUP BY pa.project_id, p.name, le.currency_code
                    ORDER BY pa.project_id';

        $results = DB::select($query, $params);

        return array_map(static function ($row) {
            return [
                'project_id' => (string) $row->project_id,
                'project_name' => $row->project_name !== null ? (string) $row->project_name : null,
                'currency_code' => (string) $row->currency_code,
                'income' => (string) $row->income,
                'expenses' => (string) $row->expenses,
                'net_profit' => (string) $row->net_profit,
            ];
        }, $results);
    }
}
