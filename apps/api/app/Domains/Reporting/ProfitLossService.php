<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Profit & Loss (project or crop-cycle scoped): income/expense by account.
 * Read-only from ledger_entries + posting_groups + accounts.
 * Uses posting_groups.posting_date. Tenant-isolated. Reversals excluded.
 * Optional filters: project_id OR crop_cycle_id (at least one required by caller).
 */
final class ProfitLossService
{
    /** Account types that contribute to income (revenue). */
    private const INCOME_TYPES = ['income'];

    /** Account types that contribute to expenses. */
    private const EXPENSE_TYPES = ['expense'];

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters At least project_id or crop_cycle_id should be set by caller.
     * @return array{meta: array{tenant_id: string, from: string, to: string, filters: array}, rows: array{income: array, expenses: array}, totals: array{income_total: float, expense_total: float, net_profit: float}}
     */
    public function getProfitLoss(string $tenantId, string $from, string $to, array $filters = []): array
    {
        $rows = $this->runPnlQuery($tenantId, $from, $to, $filters);

        $incomeLines = [];
        $expenseLines = [];
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($rows as $row) {
            $periodDebit = (float) $row->period_debit_total;
            $periodCredit = (float) $row->period_credit_total;
            $net = $periodDebit - $periodCredit;
            $line = [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'period_debit_total' => round($periodDebit, 2),
                'period_credit_total' => round($periodCredit, 2),
                'amount' => 0.0,
            ];
            if ($this->isIncomeType((string) $row->account_type)) {
                $amount = $periodCredit - $periodDebit; // positive when credit > debit
                $line['amount'] = round($amount, 2);
                $incomeTotal += $line['amount'];
                $incomeLines[] = $line;
            } else {
                $amount = $periodDebit - $periodCredit; // positive when debit > credit
                $line['amount'] = round($amount, 2);
                $expenseTotal += $line['amount'];
                $expenseLines[] = $line;
            }
        }

        usort($incomeLines, fn ($a, $b) => strcmp($a['account_code'], $b['account_code']));
        usort($expenseLines, fn ($a, $b) => strcmp($a['account_code'], $b['account_code']));

        $netProfit = round($incomeTotal - $expenseTotal, 2);
        $incomeTotal = round($incomeTotal, 2);
        $expenseTotal = round($expenseTotal, 2);

        return [
            'meta' => [
                'tenant_id' => $tenantId,
                'from' => $from,
                'to' => $to,
                'filters' => $filters,
            ],
            'rows' => [
                'income' => $incomeLines,
                'expenses' => $expenseLines,
            ],
            'totals' => [
                'income_total' => $incomeTotal,
                'expense_total' => $expenseTotal,
                'net_profit' => $netProfit,
            ],
        ];
    }

    private function isIncomeType(string $type): bool
    {
        return in_array(strtolower($type), self::INCOME_TYPES, true);
    }

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     * @return list<object{account_id: string, account_code: string, account_name: string, account_type: string, period_debit_total: string, period_credit_total: string}>
     */
    private function runPnlQuery(string $tenantId, string $from, string $to, array $filters): array
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereBetween('posting_groups.posting_date', [$from, $to])
            ->whereIn('a.type', array_merge(self::INCOME_TYPES, self::EXPENSE_TYPES))
            ->selectRaw('a.id as account_id, a.code as account_code, a.name as account_name, a.type as account_type, COALESCE(SUM(le.debit_amount),0) as period_debit_total, COALESCE(SUM(le.credit_amount),0) as period_credit_total')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        return $query->get()->all();
    }
}
