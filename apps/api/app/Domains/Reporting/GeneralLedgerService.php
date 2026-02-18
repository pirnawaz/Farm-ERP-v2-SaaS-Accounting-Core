<?php

namespace App\Domains\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * General Ledger report: account drill-down with opening, entries (running balance), closing.
 * Read-only from ledger_entries + posting_groups. Uses posting_date as accounting anchor.
 * Tenant-isolated. Required filter: account_id. Optional: project_id, crop_cycle_id.
 */
final class GeneralLedgerService
{
    /**
     * @param array{account_id: string, project_id?: string, crop_cycle_id?: string} $filters
     * @return array{meta: array{tenant_id: string, from: string, to: string, filters: array}, opening_balance: float, entries: array<int, array{ledger_entry_id: string, posting_group_id: string, posting_date: string, source_type: string, source_id: string, memo: string, account_id: string, account_code: string, account_name: string, debit: float, credit: float, running_balance: float}>, closing_balance: float}
     */
    public function getGeneralLedger(string $tenantId, string $from, string $to, array $filters = []): array
    {
        $accountId = $filters['account_id'] ?? null;
        if ($accountId === null || $accountId === '') {
            return $this->emptyResponse($tenantId, $from, $to, $filters);
        }

        $openingBalance = $this->runOpeningBalanceQuery($tenantId, $from, $filters);
        $entries = $this->runEntriesQuery($tenantId, $from, $to, $filters);

        $runningBalance = (float) $openingBalance;
        $resultEntries = [];
        foreach ($entries as $row) {
            $debit = (float) $row->debit_amount;
            $credit = (float) $row->credit_amount;
            $runningBalance += $debit - $credit;
            $postingDate = $row->posting_date;
            if (is_object($postingDate) && method_exists($postingDate, 'format')) {
                $postingDate = $postingDate->format('Y-m-d');
            } else {
                $postingDate = (string) $postingDate;
            }
            $resultEntries[] = [
                'ledger_entry_id' => $row->ledger_entry_id,
                'posting_group_id' => $row->posting_group_id,
                'posting_date' => $postingDate,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'memo' => (string) ($row->correction_reason ?? ''),
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'running_balance' => round($runningBalance, 2),
            ];
        }

        $closingBalance = round($runningBalance, 2);

        return [
            'meta' => [
                'tenant_id' => $tenantId,
                'from' => $from,
                'to' => $to,
                'filters' => $filters,
            ],
            'opening_balance' => round((float) $openingBalance, 2),
            'entries' => $resultEntries,
            'closing_balance' => $closingBalance,
        ];
    }

    /**
     * @param array{account_id: string, project_id?: string, crop_cycle_id?: string} $filters
     */
    private function emptyResponse(string $tenantId, string $from, string $to, array $filters): array
    {
        return [
            'meta' => [
                'tenant_id' => $tenantId,
                'from' => $from,
                'to' => $to,
                'filters' => $filters,
            ],
            'opening_balance' => 0.0,
            'entries' => [],
            'closing_balance' => 0.0,
        ];
    }

    /**
     * Sum (debit - credit) for posting_date < from, same filters + account_id.
     * @param array{account_id: string, project_id?: string, crop_cycle_id?: string} $filters
     */
    private function runOpeningBalanceQuery(string $tenantId, string $from, array $filters): string
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->where('posting_groups.posting_date', '<', $from)
            ->where('le.account_id', $filters['account_id'])
            ->selectRaw('COALESCE(SUM(le.debit_amount - le.credit_amount), 0) as opening');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        $row = $query->first();
        return $row ? (string) $row->opening : '0';
    }

    /**
     * Entries in range, ordered by posting_date ASC, posting_groups.id ASC, ledger_entries.id ASC.
     * @param array{account_id: string, project_id?: string, crop_cycle_id?: string} $filters
     * @return list<object{ledger_entry_id: string, posting_group_id: string, posting_date: string, source_type: string, source_id: string, correction_reason: string|null, account_id: string, account_code: string, account_name: string, debit_amount: string, credit_amount: string}>
     */
    private function runEntriesQuery(string $tenantId, string $from, string $to, array $filters): array
    {
        $query = DB::table('ledger_entries as le')
            ->join('posting_groups', 'posting_groups.id', '=', 'le.posting_group_id')
            ->join('accounts as a', 'a.id', '=', 'le.account_id')
            ->whereBetween('posting_groups.posting_date', [$from, $to])
            ->where('le.account_id', $filters['account_id'])
            ->select(
                'le.id as ledger_entry_id',
                'posting_groups.id as posting_group_id',
                'posting_groups.posting_date',
                'posting_groups.source_type',
                'posting_groups.source_id',
                'posting_groups.correction_reason',
                'a.id as account_id',
                'a.code as account_code',
                'a.name as account_name',
                'le.debit_amount',
                'le.credit_amount'
            )
            ->orderBy('posting_groups.posting_date')
            ->orderBy('posting_groups.id')
            ->orderBy('le.id');

        ReportingQuery::applyTenant($query, $tenantId);
        ReportingQuery::applyExcludeReversals($query);
        ReportingQuery::applyCropCycleFilter($query, $filters['crop_cycle_id'] ?? null);
        ReportingQuery::applyProjectFilter($query, $filters['project_id'] ?? null);

        return $query->get()->all();
    }
}
