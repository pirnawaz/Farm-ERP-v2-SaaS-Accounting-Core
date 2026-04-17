<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Machine-level P&amp;L from allocation_rows + posting_groups (Phase 7C.1).
 *
 * Revenue: MACHINERY_SERVICE_INCOME attribution via MACHINERY_SERVICE, MACHINERY_CHARGE, HARVEST_IN_KIND_MACHINE
 * (same economic scope as machinery profitability report, with Phase 3C active posting groups + logical source for reversals).
 *
 * Cost: operating costs allocated to machine (excl. revenue-only &amp; inventory rows) plus FIELD_JOB MACHINERY_SERVICE
 * amounts (Dr/Cr mirror to internal machinery income — nets with FIELD_JOB revenue). MACHINERY_CHARGE is **revenue only**
 * here; its Dr expense is pool-level and is not duplicated as machine cost (avoids double-count vs charge revenue).
 *
 * Excludes HARVEST_PRODUCTION (inventory capitalization — Phase 3C.6).
 */
class MachineProfitabilityService
{
    /**
     * @param  array{from: string, to: string, crop_cycle_id?: string|null, project_id?: string|null, machine_id?: string|null}  $filters  posting_date inclusive; optional crop_cycle_id, project_id, machine_id
     * @return list<array{machine_id: string, usage_hours: float, revenue: float, cost: float, profit: float}>
     */
    public function getMachineProfitability(string $tenantId, array $filters): array
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $cropCycleId = $filters['crop_cycle_id'] ?? null;
        $projectId = isset($filters['project_id']) && $filters['project_id'] !== '' ? (string) $filters['project_id'] : null;
        $machineId = isset($filters['machine_id']) && $filters['machine_id'] !== '' ? (string) $filters['machine_id'] : null;

        $revenue = $this->revenueByMachine($tenantId, $from, $to, $cropCycleId, $projectId, $machineId);
        $cost = $this->costByMachine($tenantId, $from, $to, $cropCycleId, $projectId, $machineId);
        $usageHours = $this->usageHoursByMachine($tenantId, $from, $to, $cropCycleId, $projectId, $machineId);

        $machineIds = collect(array_keys($revenue))
            ->merge(array_keys($cost))
            ->merge(array_keys($usageHours))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $rows = [];
        foreach ($machineIds as $mid) {
            $rev = round((float) ($revenue[$mid] ?? 0), 2);
            $cst = round((float) ($cost[$mid] ?? 0), 2);
            $hrs = round((float) ($usageHours[$mid] ?? 0), 4);
            $rows[] = [
                'machine_id' => $mid,
                'usage_hours' => $hrs,
                'revenue' => $rev,
                'cost' => $cst,
                'profit' => round($rev - $cst, 2),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, float> machine_id => revenue (allocation amount, base)
     */
    private function projectPostingGroupClause(?string $projectId): string
    {
        if ($projectId === null || $projectId === '') {
            return '';
        }

        return 'AND EXISTS (SELECT 1 FROM allocation_rows ar_proj WHERE ar_proj.posting_group_id = pg.id AND ar_proj.tenant_id = ? AND ar_proj.project_id = ?)';
    }

    private function appendProjectBindings(array $bindings, string $tenantId, ?string $projectId): array
    {
        if ($projectId !== null && $projectId !== '') {
            $bindings[] = $tenantId;
            $bindings[] = $projectId;
        }

        return $bindings;
    }

    private function machineAllocationClause(?string $machineId): string
    {
        if ($machineId === null || $machineId === '') {
            return '';
        }

        return 'AND ar.machine_id = ?';
    }

    private function appendMachineBindings(array $bindings, ?string $machineId): array
    {
        if ($machineId !== null && $machineId !== '') {
            $bindings[] = $machineId;
        }

        return $bindings;
    }

    private function revenueByMachine(string $tenantId, string $from, string $to, ?string $cropCycleId, ?string $projectId = null, ?string $machineId = null): array
    {
        $cropClause = $cropCycleId ? 'AND pg.crop_cycle_id = ?' : '';
        $projClause = $this->projectPostingGroupClause($projectId);
        $machClause = $this->machineAllocationClause($machineId);

        $sql = "
            SELECT
                ar.machine_id,
                COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)), 0) AS revenue_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id AND pg.tenant_id = ar.tenant_id
            INNER JOIN posting_groups pgo ON pgo.id = COALESCE(pg.reversal_of_posting_group_id, pg.id) AND pgo.tenant_id = pg.tenant_id
            LEFT JOIN machinery_services ms ON ms.id::text = pgo.source_id::text AND pgo.source_type = 'MACHINERY_SERVICE'
            LEFT JOIN machinery_charges mc ON mc.id::text = pgo.source_id::text AND pgo.source_type = 'MACHINERY_CHARGE'
            LEFT JOIN harvests h ON h.tenant_id = ar.tenant_id AND h.id::text = pgo.source_id::text AND pgo.source_type = 'HARVEST'
            WHERE ar.tenant_id = ?
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND pg.posting_date BETWEEN ? AND ?
                {$cropClause}
                {$projClause}
                {$machClause}
                AND NOT EXISTS (
                    SELECT 1 FROM posting_groups pg_rev
                    WHERE pg_rev.reversal_of_posting_group_id = pg.id
                )
                AND (
                    (
                        ar.allocation_type IN ('MACHINERY_SERVICE', 'MACHINERY_CHARGE', 'MACHINERY_EXTERNAL_INCOME')
                        AND (
                            (pgo.source_type = 'MACHINERY_SERVICE' AND ms.posting_group_id = pgo.id)
                            OR (pgo.source_type = 'MACHINERY_CHARGE' AND mc.posting_group_id = pgo.id)
                            OR (pgo.source_type = 'FIELD_JOB' AND ar.allocation_type = 'MACHINERY_SERVICE')
                            OR (pgo.source_type = 'MACHINE_WORK_LOG' AND ar.allocation_type = 'MACHINERY_SERVICE')
                            OR (pgo.source_type = 'MACHINERY_EXTERNAL_INCOME' AND ar.allocation_type = 'MACHINERY_EXTERNAL_INCOME')
                        )
                    )
                    OR (
                        pgo.source_type = 'HARVEST'
                        AND ar.allocation_type = 'HARVEST_IN_KIND_MACHINE'
                        AND h.id IS NOT NULL
                    )
                )
            GROUP BY ar.machine_id
        ";

        $bindings = [$tenantId, $from, $to];
        if ($cropCycleId) {
            $bindings[] = $cropCycleId;
        }
        $bindings = $this->appendProjectBindings($bindings, $tenantId, $projectId);
        $bindings = $this->appendMachineBindings($bindings, $machineId);

        $rows = DB::select($sql, $bindings);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->machine_id] = (float) $row->revenue_total;
        }

        return $out;
    }

    /**
     * Operating / attributed machinery cost per machine.
     * Includes FIELD_JOB MACHINERY_SERVICE (internal recovery expense attribution per machine).
     *
     * @return array<string, float>
     */
    private function costByMachine(string $tenantId, string $from, string $to, ?string $cropCycleId, ?string $projectId = null, ?string $machineId = null): array
    {
        $cropClause = $cropCycleId ? 'AND pg.crop_cycle_id = ?' : '';
        $projClause = $this->projectPostingGroupClause($projectId);
        $machClause = $this->machineAllocationClause($machineId);

        // Base operating costs (excl. revenue allocations & HARVEST_PRODUCTION); incl. MACHINERY_* maintenance, POOL_SHARE with machine_id, etc.
        $baseSql = "
            SELECT
                ar.machine_id,
                COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)), 0) AS costs_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id AND pg.tenant_id = ar.tenant_id
            INNER JOIN posting_groups pgo ON pgo.id = COALESCE(pg.reversal_of_posting_group_id, pg.id) AND pgo.tenant_id = pg.tenant_id
            WHERE ar.tenant_id = ?
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND pg.posting_date BETWEEN ? AND ?
                {$cropClause}
                {$projClause}
                {$machClause}
                AND NOT EXISTS (
                    SELECT 1 FROM posting_groups pg_rev
                    WHERE pg_rev.reversal_of_posting_group_id = pg.id
                )
                AND (ar.allocation_type IS NULL OR ar.allocation_type NOT IN ('MACHINERY_SERVICE', 'MACHINERY_CHARGE', 'HARVEST_IN_KIND_MACHINE'))
                AND NOT (pgo.source_type = 'HARVEST' AND ar.allocation_type = 'HARVEST_PRODUCTION')
            GROUP BY ar.machine_id
        ";

        $bindings = [$tenantId, $from, $to];
        if ($cropCycleId) {
            $bindings[] = $cropCycleId;
        }
        $bindings = $this->appendProjectBindings($bindings, $tenantId, $projectId);
        $bindings = $this->appendMachineBindings($bindings, $machineId);

        $baseRows = DB::select($baseSql, $bindings);

        // FIELD_JOB machinery internal recovery (expense attribution = MACHINERY_SERVICE amount per machine)
        $fjSql = "
            SELECT
                ar.machine_id,
                COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)), 0) AS costs_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id AND pg.tenant_id = ar.tenant_id
            INNER JOIN posting_groups pgo ON pgo.id = COALESCE(pg.reversal_of_posting_group_id, pg.id) AND pgo.tenant_id = pg.tenant_id
            WHERE ar.tenant_id = ?
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND pg.posting_date BETWEEN ? AND ?
                {$cropClause}
                {$projClause}
                {$machClause}
                AND NOT EXISTS (
                    SELECT 1 FROM posting_groups pg_rev
                    WHERE pg_rev.reversal_of_posting_group_id = pg.id
                )
                AND ar.allocation_type = 'MACHINERY_SERVICE'
                AND pgo.source_type = 'FIELD_JOB'
            GROUP BY ar.machine_id
        ";

        $fjBindings = [$tenantId, $from, $to];
        if ($cropCycleId) {
            $fjBindings[] = $cropCycleId;
        }
        $fjBindings = $this->appendProjectBindings($fjBindings, $tenantId, $projectId);
        $fjBindings = $this->appendMachineBindings($fjBindings, $machineId);

        $fjRows = DB::select($fjSql, $fjBindings);

        $out = [];
        foreach (array_merge(
            $baseRows,
            $fjRows
        ) as $row) {
            $id = (string) $row->machine_id;
            $out[$id] = ($out[$id] ?? 0) + (float) $row->costs_total;
        }

        return $out;
    }

    /**
     * Sum posted work log usage for hour-meter machines only (usage_hours).
     *
     * @return array<string, float>
     */
    private function usageHoursByMachine(string $tenantId, string $from, string $to, ?string $cropCycleId, ?string $projectId = null, ?string $machineId = null): array
    {
        $cropClause = $cropCycleId ? 'AND mwl.crop_cycle_id = ?' : '';
        $projectClause = $projectId ? 'AND mwl.project_id = ?' : '';
        $machineClause = $machineId ? 'AND mwl.machine_id = ?' : '';
        $bindings = [$tenantId, $from, $to];
        if ($cropCycleId) {
            $bindings[] = $cropCycleId;
        }
        if ($projectId) {
            $bindings[] = $projectId;
        }
        if ($machineId) {
            $bindings[] = $machineId;
        }

        $sql = "
            SELECT
                mwl.machine_id,
                COALESCE(SUM(mwl.usage_qty), 0) AS usage_hours
            FROM machine_work_logs mwl
            INNER JOIN machines m ON m.id = mwl.machine_id AND m.tenant_id = mwl.tenant_id
            WHERE mwl.tenant_id = ?
                AND mwl.status = 'POSTED'
                AND mwl.posting_date BETWEEN ? AND ?
                {$cropClause}
                {$projectClause}
                {$machineClause}
                AND UPPER(COALESCE(m.meter_unit, '')) IN ('HR', 'HOUR', 'HOURS')
            GROUP BY mwl.machine_id
        ";

        $rows = DB::select($sql, $bindings);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->machine_id] = (float) $row->usage_hours;
        }

        return $out;
    }
}
