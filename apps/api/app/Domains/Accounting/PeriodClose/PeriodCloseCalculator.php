<?php

namespace App\Domains\Accounting\PeriodClose;

use App\Domains\Reporting\ReportingQuery;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-account income/expense balances for period close.
 * Uses same scope as ProfitLossService: crop_cycle, date range, tenant, exclude reversals.
 */
final class PeriodCloseCalculator
{
    private const INCOME_TYPES = ['income'];
    private const EXPENSE_TYPES = ['expense'];
    private const ROUNDING_THRESHOLD = 0.005;

    /**
     * Per-account period activity for income & expense accounts.
     * Only includes accounts where abs(net_amount) >= 0.005.
     *
     * @return list<array{account_id: string, account_type: string, period_debit_total: float, period_credit_total: float, net_amount: float}>
     */
    public function getIncomeExpenseAccountBalances(
        string $tenantId,
        string $cropCycleId,
        string $from,
        string $to
    ): array {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereBetween('posting_groups.posting_date', [$from, $to])
            ->whereIn('a.type', array_merge(self::INCOME_TYPES, self::EXPENSE_TYPES))
            ->selectRaw('a.id as account_id, a.type as account_type, COALESCE(SUM(le.debit_amount),0) as period_debit_total, COALESCE(SUM(le.credit_amount),0) as period_credit_total')
            ->groupBy('a.id', 'a.type')
            ->orderBy('a.id');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $cropCycleId);

        $rows = $query->get();
        $result = [];

        foreach ($rows as $row) {
            $periodDebit = (float) $row->period_debit_total;
            $periodCredit = (float) $row->period_credit_total;
            $isIncome = in_array(strtolower((string) $row->account_type), self::INCOME_TYPES, true);
            $netAmount = $isIncome
                ? round($periodCredit - $periodDebit, 2)
                : round($periodDebit - $periodCredit, 2);

            if (abs($netAmount) < self::ROUNDING_THRESHOLD) {
                continue;
            }

            $result[] = [
                'account_id' => (string) $row->account_id,
                'account_type' => (string) $row->account_type,
                'period_debit_total' => $periodDebit,
                'period_credit_total' => $periodCredit,
                'net_amount' => $netAmount,
            ];
        }

        return $result;
    }
}
