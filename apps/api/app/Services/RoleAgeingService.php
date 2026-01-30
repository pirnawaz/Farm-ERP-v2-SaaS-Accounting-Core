<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Role Ageing report: buckets outstanding PARTY_CONTROL_* balances by posting_date age
 * (0-30, 31-60, 61-90, 90+ days) as of an as_of date. Role-level only; allocation_rows used only for filtering.
 */
class RoleAgeingService
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
     * @param string $asOf YYYY-MM-DD
     * @param string|null $projectId filter: only entries whose posting_group has allocation_row with this project_id
     * @param string|null $cropCycleId filter: posting_groups.crop_cycle_id
     * @return array{as_of: string, rows: array<int, array{role: string, label: string, bucket_0_30: float, bucket_31_60: float, bucket_61_90: float, bucket_90_plus: float, total_balance: float}>, totals: array{bucket_0_30: float, bucket_31_60: float, bucket_61_90: float, bucket_90_plus: float, total_balance: float}}
     */
    public function getAgeing(
        string $tenantId,
        string $asOf,
        ?string $projectId = null,
        ?string $cropCycleId = null
    ): array {
        $accountIds = $this->resolveControlAccountIds($tenantId);
        $asOfDate = Carbon::parse($asOf);
        $boundary30 = $asOfDate->copy()->subDays(30)->format('Y-m-d');
        $boundary60 = $asOfDate->copy()->subDays(60)->format('Y-m-d');
        $boundary90 = $asOfDate->copy()->subDays(90)->format('Y-m-d');

        $q = DB::table('ledger_entries as le')
            ->join('posting_groups as pg', 'le.posting_group_id', '=', 'pg.id')
            ->join('accounts as a', 'le.account_id', '=', 'a.id')
            ->where('le.tenant_id', $tenantId)
            ->whereIn('le.account_id', $accountIds)
            ->where('pg.posting_date', '<=', $asOf);

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
        ]);
        $q->selectRaw(
            'SUM(CASE WHEN pg.posting_date > ? AND pg.posting_date <= ? THEN le.debit_amount - le.credit_amount ELSE 0 END) as bucket_0_30',
            [$boundary30, $asOf]
        );
        $q->selectRaw(
            'SUM(CASE WHEN pg.posting_date > ? AND pg.posting_date <= ? THEN le.debit_amount - le.credit_amount ELSE 0 END) as bucket_31_60',
            [$boundary60, $boundary30]
        );
        $q->selectRaw(
            'SUM(CASE WHEN pg.posting_date > ? AND pg.posting_date <= ? THEN le.debit_amount - le.credit_amount ELSE 0 END) as bucket_61_90',
            [$boundary90, $boundary60]
        );
        $q->selectRaw(
            'SUM(CASE WHEN pg.posting_date <= ? THEN le.debit_amount - le.credit_amount ELSE 0 END) as bucket_90_plus',
            [$boundary90]
        );
        $q->groupBy('a.code');

        $rawRows = $q->get();
        $rows = [];
        $totals = [
            'bucket_0_30' => 0.0,
            'bucket_31_60' => 0.0,
            'bucket_61_90' => 0.0,
            'bucket_90_plus' => 0.0,
            'total_balance' => 0.0,
        ];
        foreach ($rawRows as $r) {
            $b0 = round((float) $r->bucket_0_30, 2);
            $b1 = round((float) $r->bucket_31_60, 2);
            $b2 = round((float) $r->bucket_61_90, 2);
            $b3 = round((float) $r->bucket_90_plus, 2);
            $total = round($b0 + $b1 + $b2 + $b3, 2);
            $rows[] = [
                'role' => $r->role,
                'label' => self::ROLE_LABELS[$r->role] ?? $r->role,
                'bucket_0_30' => $b0,
                'bucket_31_60' => $b1,
                'bucket_61_90' => $b2,
                'bucket_90_plus' => $b3,
                'total_balance' => $total,
            ];
            $totals['bucket_0_30'] += $b0;
            $totals['bucket_31_60'] += $b1;
            $totals['bucket_61_90'] += $b2;
            $totals['bucket_90_plus'] += $b3;
            $totals['total_balance'] += $total;
        }
        $totals['bucket_0_30'] = round($totals['bucket_0_30'], 2);
        $totals['bucket_31_60'] = round($totals['bucket_31_60'], 2);
        $totals['bucket_61_90'] = round($totals['bucket_61_90'], 2);
        $totals['bucket_90_plus'] = round($totals['bucket_90_plus'], 2);
        $totals['total_balance'] = round($totals['total_balance'], 2);

        usort($rows, fn ($a, $b) => strcmp($a['role'], $b['role']));

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * @return list<string> account IDs (PARTY_CONTROL_* only)
     */
    private function resolveControlAccountIds(string $tenantId): array
    {
        $ids = [];
        foreach (self::ROLES as $r) {
            $account = $this->partyAccountService->getPartyControlAccountByRole($tenantId, $r);
            $ids[] = $account->id;
        }
        return $ids;
    }
}
