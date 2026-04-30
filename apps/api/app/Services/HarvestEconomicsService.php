<?php

namespace App\Services;

use App\Models\AllocationRow;
use App\Models\Harvest;
use App\Models\HarvestShareLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only harvest economics from posted snapshots (Phase 7D.1).
 *
 * **Source of truth:** {@see AllocationRow} with {@code allocation_type = HARVEST_PRODUCTION} on the harvest
 * {@code posting_group_id} — {@code quantity}, {@code amount}/{@code amount_base}, and {@code rule_snapshot.recipient_role}
 * (OWNER = retained; MACHINE/LABOUR/LANDLORD/CONTRACTOR = shared). No bucket recomputation: these are the same
 * figures used at post for Dr {@code INVENTORY_PRODUCE} / stock movements.
 *
 * **Related snapshots (not re-summed here):** {@code harvest_share_lines.computed_qty} /
 * {@code computed_value_snapshot} mirror per-line buckets; {@code inv_stock_movements} (HARVEST) aggregate to the
 * same physical qty/value as the sum of production allocations.
 */
class HarvestEconomicsService
{
    /**
     * @return array{
     *   total_output_qty: float,
     *   total_output_value: float,
     *   retained_qty: float,
     *   retained_value: float,
     *   shared: array{
     *     machine: array{quantity: float, value: float},
     *     labour: array{quantity: float, value: float},
     *     landlord: array{quantity: float, value: float},
     *     contractor: array{quantity: float, value: float}
     *   }
     * }
     */
    public function getHarvestEconomics(string $harvestId, string $tenantId): array
    {
        $harvest = Harvest::query()
            ->where('id', $harvestId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (! $harvest->isPosted() || ! $harvest->posting_group_id) {
            throw new InvalidArgumentException('Harvest must be POSTED with a posting group to read economics.');
        }

        $pgId = $harvest->posting_group_id;

        $productionRows = AllocationRow::query()
            ->where('tenant_id', $tenantId)
            ->where('posting_group_id', $pgId)
            ->where('allocation_type', 'HARVEST_PRODUCTION')
            ->get();

        $totalQty = 0.0;
        $totalValue = 0.0;
        $retainedQty = 0.0;
        $retainedValue = 0.0;
        $shared = [
            HarvestShareLine::RECIPIENT_MACHINE => ['quantity' => 0.0, 'value' => 0.0],
            HarvestShareLine::RECIPIENT_LABOUR => ['quantity' => 0.0, 'value' => 0.0],
            HarvestShareLine::RECIPIENT_LANDLORD => ['quantity' => 0.0, 'value' => 0.0],
            HarvestShareLine::RECIPIENT_CONTRACTOR => ['quantity' => 0.0, 'value' => 0.0],
        ];

        foreach ($productionRows as $row) {
            $qty = $row->quantity !== null && $row->quantity !== '' ? (float) $row->quantity : 0.0;
            $value = (float) ($row->amount_base ?? $row->amount ?? 0);
            $snapshot = is_array($row->rule_snapshot) ? $row->rule_snapshot : [];
            $role = (string) ($snapshot['recipient_role'] ?? '');

            $totalQty += $qty;
            $totalValue += $value;

            if ($role === HarvestShareLine::RECIPIENT_OWNER) {
                $retainedQty += $qty;
                $retainedValue += $value;
                continue;
            }

            if (isset($shared[$role])) {
                $shared[$role]['quantity'] += $qty;
                $shared[$role]['value'] += $value;
            }
        }

        return [
            'total_output_qty' => round($totalQty, 3),
            'total_output_value' => round($totalValue, 2),
            'retained_qty' => round($retainedQty, 3),
            'retained_value' => round($retainedValue, 2),
            'shared' => [
                'machine' => [
                    'quantity' => round($shared[HarvestShareLine::RECIPIENT_MACHINE]['quantity'], 3),
                    'value' => round($shared[HarvestShareLine::RECIPIENT_MACHINE]['value'], 2),
                ],
                'labour' => [
                    'quantity' => round($shared[HarvestShareLine::RECIPIENT_LABOUR]['quantity'], 3),
                    'value' => round($shared[HarvestShareLine::RECIPIENT_LABOUR]['value'], 2),
                ],
                'landlord' => [
                    'quantity' => round($shared[HarvestShareLine::RECIPIENT_LANDLORD]['quantity'], 3),
                    'value' => round($shared[HarvestShareLine::RECIPIENT_LANDLORD]['value'], 2),
                ],
                'contractor' => [
                    'quantity' => round($shared[HarvestShareLine::RECIPIENT_CONTRACTOR]['quantity'], 3),
                    'value' => round($shared[HarvestShareLine::RECIPIENT_CONTRACTOR]['value'], 2),
                ],
            ],
        ];
    }

    /**
     * Paginated list of POSTED harvests in a posting_date window with economics snapshots (read-only).
     *
     * @param  array{
     *   from: string,
     *   to: string,
     *   project_id?: string|null,
     *   crop_cycle_id?: string|null,
     *   per_page?: int,
     *   page?: int
     * }  $filters
     */
    public function paginateHarvestEconomics(string $tenantId, array $filters): LengthAwarePaginator
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 15;
        $perPage = min(max($perPage, 1), 100);

        $q = Harvest::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->whereNotNull('posting_group_id')
            ->whereBetween('posting_date', [$from, $to]);

        if (! empty($filters['project_id'])) {
            $q->where('project_id', $filters['project_id']);
        }
        if (! empty($filters['crop_cycle_id'])) {
            $q->where('crop_cycle_id', $filters['crop_cycle_id']);
        }

        $paginator = $q->orderBy('posting_date')->orderBy('id')->paginate(
            $perPage,
            ['id', 'harvest_no', 'posting_date', 'project_id', 'crop_cycle_id']
        );

        $paginator->getCollection()->transform(function (Harvest $h) use ($tenantId) {
            return [
                'harvest_id' => $h->id,
                'harvest_no' => $h->harvest_no,
                'posting_date' => $h->posting_date?->format('Y-m-d'),
                'project_id' => $h->project_id,
                'crop_cycle_id' => $h->crop_cycle_id,
                'economics' => $this->getHarvestEconomics($h->id, $tenantId),
            ];
        });

        return $paginator;
    }

    /**
     * Single posted harvest economics (same snapshots as {@see getHarvestEconomics}) with header fields.
     *
     * @return array{harvest_id: string, harvest_no: string|null, posting_date: string|null, project_id: string|null, crop_cycle_id: string|null, economics: array<string, mixed>}
     */
    public function getHarvestEconomicsDocument(string $harvestId, string $tenantId): array
    {
        $h = Harvest::query()
            ->where('id', $harvestId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return [
            'harvest_id' => $h->id,
            'harvest_no' => $h->harvest_no,
            'posting_date' => $h->posting_date?->format('Y-m-d'),
            'project_id' => $h->project_id,
            'crop_cycle_id' => $h->crop_cycle_id,
            'economics' => $this->getHarvestEconomics($h->id, $tenantId),
        ];
    }

    /**
     * Monthly actual yield (qty/value) from posted Harvest Economics snapshots.
     *
     * Source: allocation_rows where allocation_type = HARVEST_PRODUCTION, joined to posted harvests and posting_groups
     * for scope and posting date.
     *
     * @param  array{
     *   from: string,
     *   to: string,
     *   project_id?: string|null,
     *   crop_cycle_id?: string|null
     * }  $filters
     * @return array{
     *   by_month: array<string, array{actual_yield_qty: float, actual_yield_value: float}>,
     *   totals: array{actual_yield_qty: float, actual_yield_value: float}
     * }
     */
    public function monthlyActualYieldByScope(string $tenantId, array $filters): array
    {
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $projectId = isset($filters['project_id']) ? (string) $filters['project_id'] : null;
        if ($projectId === '') {
            $projectId = null;
        }
        $cropCycleId = isset($filters['crop_cycle_id']) ? (string) $filters['crop_cycle_id'] : null;
        if ($cropCycleId === '') {
            $cropCycleId = null;
        }

        $q = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', function ($join) use ($tenantId) {
                $join->on('pg.id', '=', 'ar.posting_group_id')
                    ->where('pg.tenant_id', '=', $tenantId);
            })
            ->join('harvests as h', function ($join) use ($tenantId) {
                $join->on('h.posting_group_id', '=', 'pg.id')
                    ->where('h.tenant_id', '=', $tenantId)
                    ->where('h.status', '=', 'POSTED')
                    ->whereNotNull('h.posting_group_id');
            })
            ->where('ar.tenant_id', $tenantId)
            ->where('ar.allocation_type', 'HARVEST_PRODUCTION')
            ->whereBetween('pg.posting_date', [$from, $to]);

        if ($projectId !== null) {
            $q->where('h.project_id', $projectId);
        }
        if ($cropCycleId !== null) {
            $q->where('h.crop_cycle_id', $cropCycleId);
        }

        $rows = $q->selectRaw("
                to_char(pg.posting_date, 'YYYY-MM') as month,
                COALESCE(SUM(ar.quantity::numeric), 0) as qty,
                COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)::numeric), 0) as val
            ")
            ->groupByRaw("to_char(pg.posting_date, 'YYYY-MM')")
            ->orderByRaw("to_char(pg.posting_date, 'YYYY-MM')")
            ->get();

        $byMonth = [];
        $totQty = 0.0;
        $totVal = 0.0;

        foreach ($rows as $r) {
            $m = (string) $r->month;
            $qty = round((float) ($r->qty ?? 0), 3);
            $val = round((float) ($r->val ?? 0), 2);
            $byMonth[$m] = ['actual_yield_qty' => $qty, 'actual_yield_value' => $val];
            $totQty += $qty;
            $totVal += $val;
        }

        return [
            'by_month' => $byMonth,
            'totals' => [
                'actual_yield_qty' => round($totQty, 3),
                'actual_yield_value' => round($totVal, 2),
            ],
        ];
    }
}
