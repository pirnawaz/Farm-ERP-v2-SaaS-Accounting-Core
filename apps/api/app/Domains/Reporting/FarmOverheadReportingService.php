<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Posted cost-center overhead from ledger + allocation rows (cost_center_id set, project_id null).
 * Excludes drafts (no ledger). Uses posting_date on posting_groups.
 */
class FarmOverheadReportingService
{
    /**
     * @return array{
     *   period: array{from: string, to: string},
     *   by_cost_center: list<array{cost_center_id: string, cost_center_name: string|null, currency_code: string, income: string, expenses: string, net: string}>,
     *   by_account: list<array{cost_center_id: string, cost_center_name: string|null, account_id: string, account_code: string|null, account_name: string|null, account_type: string, currency_code: string, income: string, expenses: string, net: string}>,
     *   grand_totals: array{currency_code: string, income: string, expenses: string, net: string}
     * }
     */
    public function getOverheads(
        string $tenantId,
        string $from,
        string $to,
        ?string $costCenterId = null,
        ?string $partyId = null
    ): array {
        $ccFilter = $costCenterId !== null && $costCenterId !== '' ? ' AND ar0.cost_center_id = :cost_center_id' : '';
        $partyFilter = $partyId !== null && $partyId !== '' ? ' AND ar0.party_id = :party_id' : '';

        $allocSql = "
            SELECT DISTINCT ar0.posting_group_id, ar0.cost_center_id
            FROM allocation_rows ar0
            WHERE ar0.tenant_id = :tenant_id_alloc
              AND ar0.cost_center_id IS NOT NULL
              AND ar0.project_id IS NULL
              {$ccFilter}
              {$partyFilter}
        ";

        $params = [
            'tenant_id_alloc' => $tenantId,
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ];
        if ($costCenterId !== null && $costCenterId !== '') {
            $params['cost_center_id'] = $costCenterId;
        }
        if ($partyId !== null && $partyId !== '') {
            $params['party_id'] = $partyId;
        }

        $ledgerJoin = "
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            JOIN ({$allocSql}) ca ON ca.posting_group_id = pg.id
            LEFT JOIN cost_centers cc ON cc.id = ca.cost_center_id AND cc.tenant_id = le.tenant_id
            WHERE le.tenant_id = :tenant_id
              AND pg.posting_date BETWEEN :from AND :to
              AND a.type IN ('income', 'expense')
        ";

        $plParts = "
                SUM(CASE WHEN a.type = 'income' THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount)) ELSE 0 END) AS income,
                SUM(CASE WHEN a.type = 'expense' THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount)) ELSE 0 END) AS expenses,
                SUM(
                    CASE
                        WHEN a.type = 'income' THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                        WHEN a.type = 'expense' THEN -(COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                        ELSE 0
                    END
                ) AS net
        ";

        $byCcSql = "
            SELECT
                ca.cost_center_id,
                cc.name AS cost_center_name,
                le.currency_code,
                {$plParts}
            {$ledgerJoin}
            GROUP BY ca.cost_center_id, cc.name, le.currency_code
            ORDER BY ca.cost_center_id
        ";

        $byAccountSql = "
            SELECT
                ca.cost_center_id,
                cc.name AS cost_center_name,
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                le.currency_code,
                {$plParts}
            {$ledgerJoin}
            GROUP BY ca.cost_center_id, cc.name, a.id, a.code, a.name, a.type, le.currency_code
            HAVING ABS(SUM(
                    CASE
                        WHEN a.type = 'income' THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                        WHEN a.type = 'expense' THEN -(COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                        ELSE 0
                    END
                )) > 0.0001
            ORDER BY ca.cost_center_id, a.code
        ";

        $byCc = DB::select($byCcSql, $params);
        $byAccount = DB::select($byAccountSql, $params);

        $grandIncome = 0.0;
        $grandExpenses = 0.0;
        $grandNet = 0.0;
        $currency = 'GBP';
        foreach ($byCc as $row) {
            $currency = (string) $row->currency_code;
            $grandIncome += (float) $row->income;
            $grandExpenses += (float) $row->expenses;
            $grandNet += (float) $row->net;
        }

        $allocatedRow = DB::selectOne(
            'SELECT COALESCE(SUM(oal.amount), 0) AS s
             FROM overhead_allocation_lines oal
             INNER JOIN overhead_allocation_headers h ON h.id = oal.overhead_allocation_header_id
             WHERE h.tenant_id = ?
               AND h.status = \'POSTED\'
               AND h.allocation_date BETWEEN ? AND ?',
            [$tenantId, $from, $to]
        );
        $allocatedToProjects = round((float) ($allocatedRow->s ?? 0), 2);
        $grossOverheadExpenses = round($grandExpenses + $allocatedToProjects, 2);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'by_cost_center' => array_map(static function ($row) {
                return [
                    'cost_center_id' => (string) $row->cost_center_id,
                    'cost_center_name' => $row->cost_center_name !== null ? (string) $row->cost_center_name : null,
                    'currency_code' => (string) $row->currency_code,
                    'income' => (string) $row->income,
                    'expenses' => (string) $row->expenses,
                    'net' => (string) $row->net,
                ];
            }, $byCc),
            'by_account' => array_map(static function ($row) {
                return [
                    'cost_center_id' => (string) $row->cost_center_id,
                    'cost_center_name' => $row->cost_center_name !== null ? (string) $row->cost_center_name : null,
                    'account_id' => (string) $row->account_id,
                    'account_code' => $row->account_code !== null ? (string) $row->account_code : null,
                    'account_name' => $row->account_name !== null ? (string) $row->account_name : null,
                    'account_type' => (string) $row->account_type,
                    'currency_code' => (string) $row->currency_code,
                    'income' => (string) $row->income,
                    'expenses' => (string) $row->expenses,
                    'net' => (string) $row->net,
                ];
            }, $byAccount),
            'grand_totals' => [
                'currency_code' => $currency,
                'income' => (string) round($grandIncome, 2),
                'expenses' => (string) round($grandExpenses, 2),
                'net' => (string) round($grandNet, 2),
            ],
            'allocation_summary' => [
                'allocated_to_projects' => (string) $allocatedToProjects,
                'gross_overhead_expenses_before_allocation' => (string) $grossOverheadExpenses,
                'note' => 'Net overhead expenses in by_cost_center already reflect credits from posted overhead allocations. gross_overhead_expenses_before_allocation approximates farm overhead expense before those reclasses; allocated_to_projects is the sum posted in the period.',
            ],
        ];
    }
}
