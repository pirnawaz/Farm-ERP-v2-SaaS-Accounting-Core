<?php

namespace App\Services;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class PartyLedgerService
{
    /**
     * Get party ledger (opening balance, period rows with running balance, closing balance).
     * Uses only LedgerEntries for the given PARTY_CONTROL_* account; no AllocationRows for totals.
     *
     * @param string $tenantId
     * @param string $accountId PARTY_CONTROL_HARI / LANDLORD / KAMDAR account id
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @param string|null $projectId optional filter: only entries whose posting_group has an allocation_row with this project_id
     * @param string|null $cropCycleId optional filter: only entries whose posting_group.crop_cycle_id matches
     * @return array{opening_balance: float, closing_balance: float, rows: array<int, array{posting_date: string, posting_group_id: string, source_type: string, source_id: string, description: string|null, project_id: string|null, crop_cycle_id: string|null, debit: float, credit: float, running_balance: float}>}
     */
    public function getLedger(
        string $tenantId,
        string $accountId,
        string $from,
        string $to,
        ?string $projectId = null,
        ?string $cropCycleId = null
    ): array {
        // Opening balance: sum(debit - credit) for posting_date < from (same filters as period)
        $openingResult = DB::table('ledger_entries')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('ledger_entries.account_id', $accountId)
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.posting_date', '<', $from);

        if ($projectId !== null) {
            $openingResult->whereExists(function ($ex) use ($tenantId, $projectId) {
                $ex->select(DB::raw(1))
                    ->from('allocation_rows as ar')
                    ->whereColumn('ar.posting_group_id', 'posting_groups.id')
                    ->where('ar.tenant_id', $tenantId)
                    ->where('ar.project_id', $projectId);
            });
        }
        if ($cropCycleId !== null) {
            $openingResult->where('posting_groups.crop_cycle_id', $cropCycleId);
        }
        $openingBalance = (float) $openingResult->sum(DB::raw('ledger_entries.debit_amount - ledger_entries.credit_amount'));

        // Period rows: one row per ledger entry, ordered by posting_date, created_at, id
        $rowsQuery = LedgerEntry::query()
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('ledger_entries.account_id', $accountId)
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->whereBetween('posting_groups.posting_date', [$from, $to])
            ->orderBy('posting_groups.posting_date', 'asc')
            ->orderBy('ledger_entries.created_at', 'asc')
            ->orderBy('ledger_entries.id', 'asc');

        if ($projectId !== null) {
            $rowsQuery->whereExists(function ($ex) use ($tenantId, $projectId) {
                $ex->select(DB::raw(1))
                    ->from('allocation_rows as ar')
                    ->whereColumn('ar.posting_group_id', 'posting_groups.id')
                    ->where('ar.tenant_id', $tenantId)
                    ->where('ar.project_id', $projectId);
            });
        }
        if ($cropCycleId !== null) {
            $rowsQuery->where('posting_groups.crop_cycle_id', $cropCycleId);
        }

        $rowsQuery->select([
            'posting_groups.posting_date',
            'posting_groups.id as posting_group_id',
            'posting_groups.source_type',
            'posting_groups.source_id',
            'posting_groups.crop_cycle_id',
            'ledger_entries.debit_amount',
            'ledger_entries.credit_amount',
        ]);

        $rawRows = $rowsQuery->get();

        // Get one project_id per posting_group_id for display (no row duplication)
        $postingGroupIds = $rawRows->pluck('posting_group_id')->unique()->values()->all();
        $projectIdsByPg = [];
        if (!empty($postingGroupIds) && ($projectId === null)) {
            $firstAr = DB::table('allocation_rows')
                ->where('tenant_id', $tenantId)
                ->whereIn('posting_group_id', $postingGroupIds)
                ->select('posting_group_id', 'project_id')
                ->orderBy('posting_group_id')
                ->orderBy('id')
                ->get()
                ->unique('posting_group_id');
            foreach ($firstAr as $ar) {
                $projectIdsByPg[$ar->posting_group_id] = $ar->project_id;
            }
        } elseif ($projectId !== null) {
            foreach ($postingGroupIds as $pgId) {
                $projectIdsByPg[$pgId] = $projectId;
            }
        }

        $runningBalance = $openingBalance;
        $rows = [];
        foreach ($rawRows as $r) {
            $debit = (float) $r->debit_amount;
            $credit = (float) $r->credit_amount;
            $runningBalance += ($debit - $credit);
            $rows[] = [
                'posting_date' => $r->posting_date instanceof \Illuminate\Support\Carbon
                    ? $r->posting_date->format('Y-m-d')
                    : $r->posting_date,
                'posting_group_id' => $r->posting_group_id,
                'source_type' => $r->source_type,
                'source_id' => $r->source_id,
                'description' => $this->descriptionForSource($r->source_type),
                'project_id' => $projectIdsByPg[$r->posting_group_id] ?? null,
                'crop_cycle_id' => $r->crop_cycle_id,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => round($runningBalance, 2),
            ];
        }
        $closingBalance = $runningBalance;

        return [
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'rows' => $rows,
        ];
    }

    private function descriptionForSource(string $sourceType): ?string
    {
        return match (strtoupper($sourceType)) {
            'SETTLEMENT' => 'Settlement',
            'ADJUSTMENT' => 'Payment / Adjustment',
            'OPERATIONAL' => 'Operational',
            'ACCOUNTING_CORRECTION' => 'Accounting Correction',
            default => $sourceType,
        };
    }
}
