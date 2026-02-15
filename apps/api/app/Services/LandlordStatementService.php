<?php

namespace App\Services;

use App\Models\Party;
use Illuminate\Support\Facades\DB;

/**
 * Landlord Statement: ledger-backed report for DUE_TO_LANDLORD account,
 * scoped to a single party via allocation_rows (Maqada lease accruals and reversals).
 */
class LandlordStatementService
{
    public function __construct(
        private SystemAccountService $accountService
    ) {}

    /**
     * @return array{
     *   party: array{id: string, name: string},
     *   date_from: string,
     *   date_to: string,
     *   opening_balance: float,
     *   closing_balance: float,
     *   lines: array<int, array{
     *     posting_date: string,
     *     description: string,
     *     source_type: string,
     *     source_id: string,
     *     posting_group_id: string,
     *     debit: float,
     *     credit: float,
     *     running_balance: float,
     *     lease_id?: string,
     *     land_parcel_id?: string,
     *     project_id?: string
     *   }>
     * }
     */
    public function getStatement(
        string $tenantId,
        string $partyId,
        string $dateFrom,
        string $dateTo
    ): array {
        $party = Party::where('id', $partyId)->where('tenant_id', $tenantId)->firstOrFail();
        $dueToLandlordAccount = $this->accountService->getByCode($tenantId, 'DUE_TO_LANDLORD');

        $pgIdsForParty = function () use ($tenantId, $partyId) {
            return DB::table('allocation_rows')
                ->where('tenant_id', $tenantId)
                ->where('party_id', $partyId)
                ->select('posting_group_id');
        };

        $openingBalance = (float) DB::table('ledger_entries')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('ledger_entries.account_id', $dueToLandlordAccount->id)
            ->whereIn('ledger_entries.posting_group_id', $pgIdsForParty())
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->where('posting_groups.posting_date', '<', $dateFrom)
            ->sum(DB::raw('ledger_entries.debit_amount - ledger_entries.credit_amount'));

        $rawRows = DB::table('ledger_entries')
            ->where('ledger_entries.tenant_id', $tenantId)
            ->where('ledger_entries.account_id', $dueToLandlordAccount->id)
            ->whereIn('ledger_entries.posting_group_id', $pgIdsForParty())
            ->join('posting_groups', 'ledger_entries.posting_group_id', '=', 'posting_groups.id')
            ->whereBetween('posting_groups.posting_date', [$dateFrom, $dateTo])
            ->orderBy('posting_groups.posting_date', 'asc')
            ->orderBy('ledger_entries.created_at', 'asc')
            ->orderBy('ledger_entries.id', 'asc')
            ->select([
                'posting_groups.posting_date',
                'posting_groups.id as posting_group_id',
                'posting_groups.source_type',
                'posting_groups.source_id',
                'ledger_entries.debit_amount',
                'ledger_entries.credit_amount',
            ])
            ->get();

        $postingGroupIds = $rawRows->pluck('posting_group_id')->unique()->values()->all();
        $enrichment = $this->enrichByPostingGroup($tenantId, $postingGroupIds);

        $runningBalance = $openingBalance;
        $lines = [];
        foreach ($rawRows as $r) {
            $debit = (float) $r->debit_amount;
            $credit = (float) $r->credit_amount;
            $runningBalance += ($debit - $credit);
            $postingDate = $r->posting_date instanceof \Carbon\Carbon
                ? $r->posting_date->format('Y-m-d')
                : (string) $r->posting_date;
            $line = [
                'posting_date' => $postingDate,
                'description' => $this->descriptionForSource($r->source_type),
                'source_type' => $r->source_type,
                'source_id' => $r->source_id,
                'posting_group_id' => $r->posting_group_id,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'running_balance' => round($runningBalance, 2),
            ];
            if (isset($enrichment[$r->posting_group_id])) {
                $line['lease_id'] = $enrichment[$r->posting_group_id]['lease_id'] ?? null;
                $line['land_parcel_id'] = $enrichment[$r->posting_group_id]['land_parcel_id'] ?? null;
                $line['project_id'] = $enrichment[$r->posting_group_id]['project_id'] ?? null;
            }
            $lines[] = $line;
        }
        $closingBalance = $runningBalance;

        return [
            'party' => ['id' => $party->id, 'name' => $party->name],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'lines' => $lines,
        ];
    }

    private function descriptionForSource(string $sourceType): string
    {
        return match (strtoupper($sourceType)) {
            'LAND_LEASE_ACCRUAL' => 'Lease rent accrual',
            'REVERSAL' => 'Reversal',
            default => $sourceType,
        };
    }

    private function enrichByPostingGroup(string $tenantId, array $postingGroupIds): array
    {
        if (empty($postingGroupIds)) {
            return [];
        }
        $rows = DB::table('allocation_rows')
            ->where('tenant_id', $tenantId)
            ->whereIn('posting_group_id', $postingGroupIds)
            ->select('posting_group_id', 'project_id', 'rule_snapshot')
            ->get();
        $out = [];
        foreach ($rows as $ar) {
            $raw = $ar->rule_snapshot;
            $snapshot = is_array($raw) ? $raw : (json_decode(is_string($raw) ? $raw : '{}', true) ?? []);
            $out[$ar->posting_group_id] = [
                'project_id' => $ar->project_id,
                'lease_id' => $snapshot['lease_id'] ?? null,
                'land_parcel_id' => $snapshot['land_parcel_id'] ?? null,
            ];
        }
        return $out;
    }
}
