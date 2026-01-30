<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Party Summary report: one row per control account (Hari/Landlord/Kamdar) with opening balance,
 * period movement, and closing balance from PARTY_CONTROL_* ledger entries only.
 * Party/role is determined strictly from the control account; allocation_rows used only for filtering.
 */
class PartySummaryService
{
    private const ROLES = ['HARI', 'LANDLORD', 'KAMDAR'];

    private const ROLE_LABELS = [
        'HARI' => 'All Haris',
        'LANDLORD' => 'All Landlords',
        'KAMDAR' => 'All Kamdars',
    ];

    public function __construct(
        private PartyAccountService $partyAccountService
    ) {}

    /**
     * @param string $tenantId
     * @param string $from YYYY-MM-DD
     * @param string $to YYYY-MM-DD
     * @param string|null $role HARI|LANDLORD|KAMDAR to restrict to one control account
     * @param string|null $projectId filter: only entries whose posting_group has allocation_row with this project_id
     * @param string|null $cropCycleId filter: posting_groups.crop_cycle_id
     * @return array{from: string, to: string, rows: array<int, array{party_id: string|null, party_name: string, role: string, opening_balance: float, period_movement: float, closing_balance: float}>, totals: array{opening_balance: float, period_movement: float, closing_balance: float}}
     */
    public function getSummary(
        string $tenantId,
        string $from,
        string $to,
        ?string $role = null,
        ?string $projectId = null,
        ?string $cropCycleId = null
    ): array {
        $accountIds = $this->resolveControlAccountIds($tenantId, $role);

        $openingByAccount = $this->aggregateByAccount(
            $tenantId,
            $accountIds,
            '<',
            $from,
            $projectId,
            $cropCycleId
        );
        $movementByAccount = $this->aggregateByAccount(
            $tenantId,
            $accountIds,
            'between',
            [$from, $to],
            $projectId,
            $cropCycleId
        );

        $keys = array_unique(array_merge(array_keys($openingByAccount), array_keys($movementByAccount)));
        $totalsOpening = 0.0;
        $totalsMovement = 0.0;
        $totalsClosing = 0.0;
        $rows = [];
        foreach ($keys as $roleValue) {
            $opening = $openingByAccount[$roleValue] ?? 0.0;
            $movement = $movementByAccount[$roleValue] ?? 0.0;
            $closing = round($opening + $movement, 2);
            $opening = round($opening, 2);
            $movement = round($movement, 2);
            $rows[] = [
                'party_id' => null,
                'party_name' => self::ROLE_LABELS[$roleValue] ?? $roleValue,
                'role' => $roleValue,
                'opening_balance' => $opening,
                'period_movement' => $movement,
                'closing_balance' => $closing,
            ];
            $totalsOpening += $opening;
            $totalsMovement += $movement;
            $totalsClosing += $closing;
        }

        usort($rows, fn ($a, $b) => strcmp($a['role'], $b['role']));

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'opening_balance' => round($totalsOpening, 2),
                'period_movement' => round($totalsMovement, 2),
                'closing_balance' => round($totalsClosing, 2),
            ],
        ];
    }

    /**
     * @return list<string> account IDs (PARTY_CONTROL_* only)
     */
    private function resolveControlAccountIds(string $tenantId, ?string $role): array
    {
        if ($role !== null) {
            $account = $this->partyAccountService->getPartyControlAccountByRole($tenantId, $role);
            return [$account->id];
        }
        $ids = [];
        foreach (self::ROLES as $r) {
            $account = $this->partyAccountService->getPartyControlAccountByRole($tenantId, $r);
            $ids[] = $account->id;
        }
        return $ids;
    }

    /**
     * Aggregate ledger entries by control account only (no allocation_rows for attribution).
     * Returns map keyed by role (HARI|LANDLORD|KAMDAR) => sum(debit - credit).
     *
     * @param list<string> $accountIds
     * @param string $dateOp '<' or 'between'
     * @param string|array{0: string, 1: string} $dateValue
     * @return array<string, float>
     */
    private function aggregateByAccount(
        string $tenantId,
        array $accountIds,
        string $dateOp,
        $dateValue,
        ?string $projectId,
        ?string $cropCycleId
    ): array {
        $q = DB::table('ledger_entries as le')
            ->join('posting_groups as pg', 'le.posting_group_id', '=', 'pg.id')
            ->join('accounts as a', 'le.account_id', '=', 'a.id')
            ->where('le.tenant_id', $tenantId)
            ->whereIn('le.account_id', $accountIds);

        if ($dateOp === '<') {
            $q->where('pg.posting_date', '<', $dateValue);
        } else {
            $q->whereBetween('pg.posting_date', $dateValue);
        }

        if ($projectId !== null) {
            $q->whereExists(function ($ex) use ($tenantId, $projectId) {
                $ex->select(DB::raw(1))
                    ->from('allocation_rows as ar2')
                    ->whereColumn('ar2.posting_group_id', 'pg.id')
                    ->where('ar2.tenant_id', $tenantId)
                    ->where('ar2.project_id', $projectId);
            });
        }
        if ($cropCycleId !== null) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }

        $q->select([
            DB::raw("CASE a.code WHEN 'PARTY_CONTROL_HARI' THEN 'HARI' WHEN 'PARTY_CONTROL_LANDLORD' THEN 'LANDLORD' WHEN 'PARTY_CONTROL_KAMDAR' THEN 'KAMDAR' END as role"),
            DB::raw('SUM(le.debit_amount - le.credit_amount) as net'),
        ])->groupBy('a.code');

        $result = [];
        foreach ($q->get() as $row) {
            $result[$row->role] = (float) $row->net;
        }
        return $result;
    }
}
