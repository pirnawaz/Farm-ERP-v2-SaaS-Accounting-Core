<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Balance Sheet as-of a date: assets, liabilities, equity.
 * Read-only from ledger_entries + posting_groups + accounts.
 * Uses posting_groups.posting_date <= asOf. Tenant-isolated. Reversals excluded.
 * Optional filters: project_id (via allocation_rows), crop_cycle_id.
 */
final class BalanceSheetService
{
    private const BALANCE_TOLERANCE = 0.01;

    /** Account types for balance sheet sections. */
    private const ASSET_TYPES = ['asset'];
    private const LIABILITY_TYPES = ['liability'];
    private const EQUITY_TYPES = ['equity'];

    private const INCOME_TYPES = ['income'];
    private const EXPENSE_TYPES = ['expense'];

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     * @return array{meta: array{tenant_id: string, as_of: string, filters: array}, sections: array{assets: array, liabilities: array, equity: array}, totals: array{assets_total: float, liabilities_total: float, equity_total: float, liabilities_plus_equity_total: float, balanced: bool}}
     */
    public function getBalanceSheet(string $tenantId, string $asOf, array $filters = []): array
    {
        $rows = $this->runBalanceSheetQuery($tenantId, $asOf, $filters);

        $assetLines = [];
        $liabilityLines = [];
        $equityLines = [];
        $assetsTotal = 0.0;
        $liabilitiesTotal = 0.0;
        $equityTotal = 0.0;

        foreach ($rows as $row) {
            $periodDebit = (float) $row->period_debit_total;
            $periodCredit = (float) $row->period_credit_total;
            $debitMinusCredit = $periodDebit - $periodCredit;
            $type = strtolower((string) $row->account_type);

            $line = [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'currency_code' => (string) ($row->currency_code ?? 'GBP'),
                'period_debit_total' => round($periodDebit, 2),
                'period_credit_total' => round($periodCredit, 2),
                'net' => 0.0,
            ];

            if ($this->isAssetType($type)) {
                $net = $debitMinusCredit;
                $line['net'] = round($net, 2);
                if (abs($net) >= 0.005) {
                    $assetLines[] = $line;
                    $assetsTotal += $net;
                }
            } elseif ($this->isLiabilityType($type)) {
                $net = $periodCredit - $periodDebit;
                $line['net'] = round($net, 2);
                if (abs($net) >= 0.005) {
                    $liabilityLines[] = $line;
                    $liabilitiesTotal += $net;
                }
            } else {
                $net = $periodCredit - $periodDebit;
                $line['net'] = round($net, 2);
                if (abs($net) >= 0.005) {
                    $equityLines[] = $line;
                    $equityTotal += $net;
                }
            }
        }

        // Net profit to date (income - expense) so equation balances when no retained earnings account
        $netProfitToDate = $this->netProfitToDate($tenantId, $asOf, $filters);
        if (abs($netProfitToDate) >= 0.005) {
            $equityLines[] = [
                'account_id' => null,
                'account_code' => null,
                'account_name' => 'Net profit to date',
                'account_type' => 'equity',
                'currency_code' => 'GBP',
                'period_debit_total' => 0.0,
                'period_credit_total' => 0.0,
                'net' => round($netProfitToDate, 2),
            ];
            $equityTotal += $netProfitToDate;
        }

        usort($assetLines, fn ($a, $b) => strcmp($a['account_code'] ?? '', $b['account_code'] ?? ''));
        usort($liabilityLines, fn ($a, $b) => strcmp($a['account_code'] ?? '', $b['account_code'] ?? ''));
        usort($equityLines, fn ($a, $b) => strcmp($a['account_code'] ?? '', $b['account_code'] ?? ''));

        $assetsTotal = round($assetsTotal, 2);
        $liabilitiesTotal = round($liabilitiesTotal, 2);
        $equityTotal = round($equityTotal, 2);
        $liabilitiesPlusEquityTotal = round($liabilitiesTotal + $equityTotal, 2);
        $balanced = abs($assetsTotal - $liabilitiesPlusEquityTotal) <= self::BALANCE_TOLERANCE;

        return [
            'meta' => [
                'tenant_id' => $tenantId,
                'as_of' => $asOf,
                'filters' => $filters,
            ],
            'sections' => [
                'assets' => $assetLines,
                'liabilities' => $liabilityLines,
                'equity' => $equityLines,
            ],
            'totals' => [
                'assets_total' => $assetsTotal,
                'liabilities_total' => $liabilitiesTotal,
                'equity_total' => $equityTotal,
                'liabilities_plus_equity_total' => $liabilitiesPlusEquityTotal,
                'balanced' => $balanced,
            ],
        ];
    }

    private function isAssetType(string $type): bool
    {
        return in_array($type, self::ASSET_TYPES, true);
    }

    private function isLiabilityType(string $type): bool
    {
        return in_array($type, self::LIABILITY_TYPES, true);
    }

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     * @return list<object{account_id: string, account_code: string, account_name: string, account_type: string, currency_code: string, period_debit_total: string, period_credit_total: string}>
     */
    private function runBalanceSheetQuery(string $tenantId, string $asOf, array $filters): array
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('posting_groups.posting_date', '<=', $asOf)
            ->whereIn('a.type', array_merge(self::ASSET_TYPES, self::LIABILITY_TYPES, self::EQUITY_TYPES))
            ->selectRaw('a.id as account_id, a.code as account_code, a.name as account_name, a.type as account_type, le.currency_code, COALESCE(SUM(le.debit_amount),0) as period_debit_total, COALESCE(SUM(le.credit_amount),0) as period_credit_total')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'le.currency_code')
            ->orderBy('a.type')
            ->orderBy('a.code');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        return $query->get()->all();
    }

    /**
     * Net profit (income - expense) for posting_date <= asOf with same filters.
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     */
    private function netProfitToDate(string $tenantId, string $asOf, array $filters): float
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('posting_groups.posting_date', '<=', $asOf)
            ->whereIn('a.type', array_merge(self::INCOME_TYPES, self::EXPENSE_TYPES))
            ->selectRaw('a.type as account_type, COALESCE(SUM(le.debit_amount),0) as period_debit_total, COALESCE(SUM(le.credit_amount),0) as period_credit_total')
            ->groupBy('a.id', 'a.type');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        $rows = $query->get();
        $income = 0.0;
        $expense = 0.0;
        foreach ($rows as $row) {
            $dr = (float) $row->period_debit_total;
            $cr = (float) $row->period_credit_total;
            $type = strtolower((string) $row->account_type);
            if (in_array($type, self::INCOME_TYPES, true)) {
                $income += $cr - $dr;
            } else {
                $expense += $dr - $cr;
            }
        }
        return round($income - $expense, 2);
    }
}
