<?php

namespace App\Domains\Accounting\Reports;

use Illuminate\Support\Facades\DB;

/**
 * Read-only financial statements from ledger_entries + posting_groups.
 * Uses posting_groups.posting_date for all cutoffs. Tenant-isolated.
 * Presentation signs: income/expense/asset/liability/equity per spec.
 */
final class FinancialStatementsService
{
    private const EQUATION_TOLERANCE = 0.01;

    /**
     * Profit & Loss (Income Statement) for date range.
     * INCOME: display positive when credit > debit → amount = max(0, -net).
     * EXPENSE: display positive when debit > credit → amount = max(0, net).
     *
     * @return array{from: string, to: string, sections: array, net_profit: float, compare?: array}
     */
    public function getProfitLoss(
        string $tenantId,
        string $from,
        string $to,
        ?string $compareFrom = null,
        ?string $compareTo = null
    ): array {
        $rows = $this->runPnlQuery($tenantId, $from, $to);
        $incomeLines = [];
        $expenseLines = [];
        $incomeTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($rows as $row) {
            $net = (float) $row->net;
            $line = [
                'account_id' => $row->account_id,
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => 0.0,
            ];
            if ($row->account_type === 'income') {
                $amount = max(0.0, -$net);
                $line['amount'] = round($amount, 2);
                $incomeTotal += $amount;
                $incomeLines[] = $line;
            } else {
                $amount = max(0.0, $net);
                $line['amount'] = round($amount, 2);
                $expenseTotal += $amount;
                $expenseLines[] = $line;
            }
        }

        usort($incomeLines, fn ($a, $b) => strcmp($a['code'], $b['code']));
        usort($expenseLines, fn ($a, $b) => strcmp($a['code'], $b['code']));

        $netProfit = round($incomeTotal - $expenseTotal, 2);

        $compare = null;
        if ($compareFrom !== null && $compareTo !== null) {
            $compareRows = $this->runPnlQuery($tenantId, $compareFrom, $compareTo);
            $compareIncome = 0.0;
            $compareExpense = 0.0;
            $compareIncomeByAccount = [];
            $compareExpenseByAccount = [];
            foreach ($compareRows as $row) {
                $net = (float) $row->net;
                if ($row->account_type === 'income') {
                    $amt = max(0.0, -$net);
                    $compareIncome += $amt;
                    $compareIncomeByAccount[$row->account_id] = round($amt, 2);
                } else {
                    $amt = max(0.0, $net);
                    $compareExpense += $amt;
                    $compareExpenseByAccount[$row->account_id] = round($amt, 2);
                }
            }
            $compareNetProfit = round($compareIncome - $compareExpense, 2);
            $delta = round($netProfit - $compareNetProfit, 2);
            foreach ($incomeLines as &$line) {
                $line['compare_amount'] = $compareIncomeByAccount[$line['account_id']] ?? 0.0;
                $line['delta'] = round($line['amount'] - ($line['compare_amount'] ?? 0), 2);
            }
            unset($line);
            foreach ($expenseLines as &$line) {
                $line['compare_amount'] = $compareExpenseByAccount[$line['account_id']] ?? 0.0;
                $line['delta'] = round($line['amount'] - ($line['compare_amount'] ?? 0), 2);
            }
            unset($line);
            $compare = [
                'from' => $compareFrom,
                'to' => $compareTo,
                'net_profit' => $compareNetProfit,
                'delta' => $delta,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'sections' => [
                ['key' => 'income', 'label' => 'Income', 'lines' => $incomeLines, 'total' => round($incomeTotal, 2)],
                ['key' => 'expenses', 'label' => 'Expenses', 'lines' => $expenseLines, 'total' => round($expenseTotal, 2)],
            ],
            'net_profit' => $netProfit,
            'compare' => $compare,
        ];
    }

    /**
     * Balance Sheet as-of a date.
     * ASSET: amount = net (debit - credit).
     * LIABILITY/EQUITY: amount = -net (display positive when credit > debit).
     * Adds synthetic "Net profit to date" in equity so equation balances when no RE account.
     *
     * @return array{as_of: string, assets: array, liabilities: array, equity: array, checks: array, compare?: array}
     */
    public function getBalanceSheet(
        string $tenantId,
        string $asOf,
        ?string $compareAsOf = null
    ): array {
        $rows = $this->runBalanceSheetQuery($tenantId, $asOf);
        $assetLines = [];
        $liabilityLines = [];
        $equityLines = [];
        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        foreach ($rows as $row) {
            $net = (float) $row->net;
            $line = [
                'account_id' => $row->account_id,
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => 0.0,
            ];
            if ($row->account_type === 'asset') {
                $line['amount'] = round($net, 2);
                $totalAssets += $net;
                $assetLines[] = $line;
            } elseif ($row->account_type === 'liability') {
                $line['amount'] = round(-$net, 2);
                $totalLiabilities += -$net;
                $liabilityLines[] = $line;
            } else {
                $line['amount'] = round(-$net, 2);
                $totalEquity += -$net;
                $equityLines[] = $line;
            }
        }

        usort($assetLines, fn ($a, $b) => strcmp($a['code'], $b['code']));
        usort($liabilityLines, fn ($a, $b) => strcmp($a['code'], $b['code']));
        usort($equityLines, fn ($a, $b) => strcmp($a['code'], $b['code']));

        // Net profit from start of fiscal year (calendar) to as_of; add to equity so equation balances
        $yearStart = substr($asOf, 0, 4) . '-01-01';
        $netProfitToDate = $this->netProfitForRange($tenantId, $yearStart, $asOf);
        if (abs($netProfitToDate) >= 0.005) {
            $equityLines[] = [
                'account_id' => null,
                'code' => null,
                'name' => 'Net profit to date',
                'amount' => round($netProfitToDate, 2),
            ];
            $totalEquity += $netProfitToDate;
        }

        $totalEquity = round($totalEquity, 2);
        $totalAssets = round($totalAssets, 2);
        $totalLiabilities = round($totalLiabilities, 2);
        $equationDiff = round($totalAssets - ($totalLiabilities + $totalEquity), 2);

        $compare = null;
        if ($compareAsOf !== null) {
            $compareData = $this->buildBalanceSheetSections($tenantId, $compareAsOf);
            $compare = [
                'as_of' => $compareAsOf,
                'assets' => $compareData['assets'],
                'liabilities' => $compareData['liabilities'],
                'equity' => $compareData['equity'],
                'total_assets' => $compareData['total_assets'],
                'total_liabilities' => $compareData['total_liabilities'],
                'total_equity' => $compareData['total_equity'],
            ];
        }

        return [
            'as_of' => $asOf,
            'assets' => ['lines' => $assetLines, 'total' => $totalAssets],
            'liabilities' => ['lines' => $liabilityLines, 'total' => $totalLiabilities],
            'equity' => ['lines' => $equityLines, 'total' => $totalEquity],
            'checks' => ['equation_diff' => $equationDiff],
            'compare' => $compare,
        ];
    }

    /**
     * Net profit (income - expense) for a date range. Same sign convention as P&L.
     */
    public function netProfitForRange(string $tenantId, string $from, string $to): float
    {
        $rows = $this->runPnlQuery($tenantId, $from, $to);
        $income = 0.0;
        $expense = 0.0;
        foreach ($rows as $row) {
            $net = (float) $row->net;
            if ($row->account_type === 'income') {
                $income += max(0.0, -$net);
            } else {
                $expense += max(0.0, $net);
            }
        }
        return round($income - $expense, 2);
    }

    /**
     * @return list<object{account_id: string, account_code: string, account_name: string, account_type: string, net: string}>
     */
    private function runPnlQuery(string $tenantId, string $from, string $to): array
    {
        $sql = "
            SELECT
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                SUM(le.debit_amount - le.credit_amount) AS net
            FROM ledger_entries le
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            JOIN accounts a ON a.id = le.account_id
            WHERE pg.tenant_id = :tenant_id
              AND pg.posting_date BETWEEN :from AND :to
              AND a.type IN ('income', 'expense')
            GROUP BY a.id, a.code, a.name, a.type
            ORDER BY a.code
        ";
        return DB::select($sql, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * @return list<object{account_id: string, account_code: string, account_name: string, account_type: string, net: string}>
     */
    private function runBalanceSheetQuery(string $tenantId, string $asOf): array
    {
        $sql = "
            SELECT
                a.id AS account_id,
                a.code AS account_code,
                a.name AS account_name,
                a.type AS account_type,
                SUM(le.debit_amount - le.credit_amount) AS net
            FROM ledger_entries le
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            JOIN accounts a ON a.id = le.account_id
            WHERE pg.tenant_id = :tenant_id
              AND pg.posting_date <= :as_of
              AND a.type IN ('asset', 'liability', 'equity')
            GROUP BY a.id, a.code, a.name, a.type
            ORDER BY a.type, a.code
        ";
        return DB::select($sql, [
            'tenant_id' => $tenantId,
            'as_of' => $asOf,
        ]);
    }

    private function buildBalanceSheetSections(string $tenantId, string $asOf): array
    {
        $rows = $this->runBalanceSheetQuery($tenantId, $asOf);
        $assetLines = [];
        $liabilityLines = [];
        $equityLines = [];
        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        foreach ($rows as $row) {
            $net = (float) $row->net;
            $line = [
                'account_id' => $row->account_id,
                'code' => $row->account_code,
                'name' => $row->account_name,
                'amount' => 0.0,
            ];
            if ($row->account_type === 'asset') {
                $line['amount'] = round($net, 2);
                $totalAssets += $net;
                $assetLines[] = $line;
            } elseif ($row->account_type === 'liability') {
                $line['amount'] = round(-$net, 2);
                $totalLiabilities += -$net;
                $liabilityLines[] = $line;
            } else {
                $line['amount'] = round(-$net, 2);
                $totalEquity += -$net;
                $equityLines[] = $line;
            }
        }

        $yearStart = substr($asOf, 0, 4) . '-01-01';
        $netProfitToDate = $this->netProfitForRange($tenantId, $yearStart, $asOf);
        if (abs($netProfitToDate) >= 0.005) {
            $equityLines[] = [
                'account_id' => null,
                'code' => null,
                'name' => 'Net profit to date',
                'amount' => round($netProfitToDate, 2),
            ];
            $totalEquity += $netProfitToDate;
        }

        return [
            'assets' => ['lines' => $assetLines, 'total' => round($totalAssets, 2)],
            'liabilities' => ['lines' => $liabilityLines, 'total' => round($totalLiabilities, 2)],
            'equity' => ['lines' => $equityLines, 'total' => round($totalEquity, 2)],
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => round($totalEquity, 2),
        ];
    }
}
