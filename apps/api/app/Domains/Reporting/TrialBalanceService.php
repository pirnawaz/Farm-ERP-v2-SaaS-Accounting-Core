<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * Trial Balance report: rows grouped by account, as-of a date.
 * Read-only from ledger_entries + posting_groups. Uses posting_date as accounting anchor.
 * Tenant-isolated. Optional filters: project_id (via crop_cycle), crop_cycle_id.
 */
final class TrialBalanceService
{
    private const BALANCE_TOLERANCE = 0.01;

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     * @return array{rows: array<int, array{account_id: string, account_code: string, account_name: string, account_type: string, currency_code: string, total_debit: string, total_credit: string, net: string}>, totals: array{total_debit: string, total_credit: string}, balanced: bool}
     */
    public function getTrialBalance(string $tenantId, string $asOf, array $filters = []): array
    {
        $rows = $this->runTrialBalanceQuery($tenantId, $asOf, $filters);

        $totalDebit = '0';
        $totalCredit = '0';
        $resultRows = [];

        foreach ($rows as $row) {
            $totalDebit = bcadd($totalDebit, (string) $row->total_debit, 2);
            $totalCredit = bcadd($totalCredit, (string) $row->total_credit, 2);
            $resultRows[] = [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'currency_code' => $row->currency_code,
                'total_debit' => (string) $row->total_debit,
                'total_credit' => (string) $row->total_credit,
                'net' => (string) $row->net,
            ];
        }

        $balanced = abs((float) $totalDebit - (float) $totalCredit) <= self::BALANCE_TOLERANCE;

        return [
            'as_of' => $asOf,
            'rows' => $resultRows,
            'totals' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ],
            'balanced' => $balanced,
        ];
    }

    /**
     * @param array{project_id?: string, crop_cycle_id?: string} $filters
     * @return list<object{account_id: string, account_code: string, account_name: string, account_type: string, currency_code: string, total_debit: string, total_credit: string, net: string}>
     */
    private function runTrialBalanceQuery(string $tenantId, string $asOf, array $filters): array
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('posting_groups.posting_date', '<=', $asOf)
            ->selectRaw('a.id as account_id, a.code as account_code, a.name as account_name, a.type as account_type, le.currency_code, COALESCE(SUM(le.debit_amount),0) as total_debit, COALESCE(SUM(le.credit_amount),0) as total_credit, COALESCE(SUM(le.debit_amount - le.credit_amount),0) as net')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'le.currency_code')
            ->orderBy('a.code');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        return $query->get()->all();
    }
}
