<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MachineryReportsController extends Controller
{
    /**
     * GET /api/v1/machinery/reports/charges-by-machine
     * Returns charges grouped by machine for a date range
     */
    public function chargesByMachine(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date', 'date_format:Y-m-d'],
            'to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');

        $query = "
            SELECT
                m.id AS machine_id,
                m.code AS machine_code,
                m.name AS machine_name,
                MAX(mcl.unit) AS unit,
                COALESCE(SUM(mcl.usage_qty), 0) AS usage_qty,
                COALESCE(SUM(mcl.amount), 0) AS charges_total
            FROM machinery_charges mc
            INNER JOIN machinery_charge_lines mcl ON mcl.machinery_charge_id = mc.id
            INNER JOIN machine_work_logs mwl ON mwl.id = mcl.machine_work_log_id
            INNER JOIN machines m ON m.id = mwl.machine_id
            WHERE mc.tenant_id = :tenant_id
                AND mc.status = 'POSTED'
                AND mc.posting_date BETWEEN :from AND :to
            GROUP BY m.id, m.code, m.name
            ORDER BY m.code
        ";

        $results = DB::select($query, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        $rows = array_map(function ($row) {
            return [
                'machine_id' => $row->machine_id,
                'machine_code' => $row->machine_code,
                'machine_name' => $row->machine_name,
                'unit' => $row->unit,
                'usage_qty' => (string) round((float) $row->usage_qty, 2),
                'charges_total' => (string) round((float) $row->charges_total, 2),
            ];
        }, $results);

        return response()->json($rows);
    }

    /**
     * GET /api/v1/machinery/reports/costs-by-machine
     * Returns costs grouped by machine and allocation type for a date range
     */
    public function costsByMachine(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date', 'date_format:Y-m-d'],
            'to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');

        // First get totals per machine
        $totalsQuery = "
            SELECT
                ar.machine_id,
                m.code AS machine_code,
                m.name AS machine_name,
                COALESCE(SUM(ar.amount), 0) AS costs_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id
            INNER JOIN machines m ON m.id = ar.machine_id
            WHERE ar.tenant_id = :tenant_id
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND pg.posting_date BETWEEN :from AND :to
            GROUP BY ar.machine_id, m.code, m.name
            ORDER BY m.code
        ";

        $totals = DB::select($totalsQuery, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        // Get breakdown by allocation_type per machine
        $breakdownQuery = "
            SELECT
                ar.machine_id,
                ar.allocation_type,
                COALESCE(SUM(ar.amount), 0) AS amount
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id
            WHERE ar.tenant_id = :tenant_id
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND pg.posting_date BETWEEN :from AND :to
            GROUP BY ar.machine_id, ar.allocation_type
            ORDER BY ar.machine_id, ar.allocation_type
        ";

        $breakdowns = DB::select($breakdownQuery, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        // Group breakdowns by machine_id
        $breakdownByMachine = [];
        foreach ($breakdowns as $bd) {
            if (!isset($breakdownByMachine[$bd->machine_id])) {
                $breakdownByMachine[$bd->machine_id] = [];
            }
            $breakdownByMachine[$bd->machine_id][] = [
                'key' => $bd->allocation_type,
                'amount' => (string) round((float) $bd->amount, 2),
            ];
        }

        // Combine totals with breakdowns
        $rows = array_map(function ($row) use ($breakdownByMachine) {
            return [
                'machine_id' => $row->machine_id,
                'machine_code' => $row->machine_code,
                'machine_name' => $row->machine_name,
                'costs_total' => (string) round((float) $row->costs_total, 2),
                'breakdown' => $breakdownByMachine[$row->machine_id] ?? [],
            ];
        }, $totals);

        return response()->json($rows);
    }

    /**
     * GET /api/v1/machinery/reports/profitability
     * Returns profitability report combining usage, charges, and costs per machine
     */
    public function profitability(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'date', 'date_format:Y-m-d'],
            'to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $from = $request->input('from');
        $to = $request->input('to');

        // Get usage from work logs (POSTED, posting_date in range)
        $usageQuery = "
            SELECT
                m.id AS machine_id,
                m.code AS machine_code,
                m.name AS machine_name,
                m.meter_unit,
                COALESCE(SUM(mwl.usage_qty), 0) AS usage_qty
            FROM machine_work_logs mwl
            INNER JOIN machines m ON m.id = mwl.machine_id
            WHERE mwl.tenant_id = :tenant_id
                AND mwl.status = 'POSTED'
                AND mwl.posting_date BETWEEN :from AND :to
            GROUP BY m.id, m.code, m.name, m.meter_unit
        ";

        $usage = DB::select($usageQuery, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        // Revenue = income postings (MACHINERY_SERVICE_INCOME) by machine from allocation_rows
        // Exclude reversed: only include PG where source record has reversal_posting_group_id IS NULL
        $revenueQuery = "
            SELECT
                ar.machine_id,
                COALESCE(SUM(ar.amount), 0) AS revenue_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id
            LEFT JOIN machinery_services ms ON ms.id::text = pg.source_id::text AND pg.source_type = 'MACHINERY_SERVICE'
            LEFT JOIN machinery_charges mc ON mc.id::text = pg.source_id::text AND pg.source_type = 'MACHINERY_CHARGE'
            WHERE ar.tenant_id = :tenant_id
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND ar.allocation_type IN ('MACHINERY_SERVICE', 'MACHINERY_CHARGE')
                AND pg.posting_date BETWEEN :from AND :to
                AND (
                    (pg.source_type = 'MACHINERY_SERVICE' AND ms.posting_group_id = pg.id AND ms.reversal_posting_group_id IS NULL)
                    OR (pg.source_type = 'MACHINERY_CHARGE' AND mc.posting_group_id = pg.id AND mc.reversal_posting_group_id IS NULL)
                )
            GROUP BY ar.machine_id
        ";

        $revenue = DB::select($revenueQuery, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        // Costs = allocation rows with machine_id excluding revenue types (MACHINERY_SERVICE, MACHINERY_CHARGE)
        // e.g. MACHINERY_MAINTENANCE and work log cost postings; exclude reversed via source
        $costsQuery = "
            SELECT
                ar.machine_id,
                COALESCE(SUM(ar.amount), 0) AS costs_total
            FROM allocation_rows ar
            INNER JOIN posting_groups pg ON pg.id = ar.posting_group_id
            WHERE ar.tenant_id = :tenant_id
                AND ar.machine_id IS NOT NULL
                AND ar.amount IS NOT NULL
                AND (ar.allocation_type IS NULL OR ar.allocation_type NOT IN ('MACHINERY_SERVICE', 'MACHINERY_CHARGE'))
                AND pg.posting_date BETWEEN :from AND :to
            GROUP BY ar.machine_id
        ";

        $costs = DB::select($costsQuery, [
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
        ]);

        // Index by machine_id for easy lookup
        $usageByMachine = [];
        foreach ($usage as $u) {
            $usageByMachine[$u->machine_id] = $u;
        }

        $revenueByMachine = [];
        foreach ($revenue as $r) {
            $revenueByMachine[$r->machine_id] = (float) $r->revenue_total;
        }

        $costsByMachine = [];
        foreach ($costs as $c) {
            $costsByMachine[$c->machine_id] = (float) $c->costs_total;
        }

        // Get all unique machines from usage, revenue, or costs
        $allMachineIds = array_unique(array_merge(
            array_keys($usageByMachine),
            array_keys($revenueByMachine),
            array_keys($costsByMachine)
        ));

        // Build result rows
        $rows = [];
        foreach ($allMachineIds as $machineId) {
            $usageData = $usageByMachine[$machineId] ?? null;
            $revenueTotal = $revenueByMachine[$machineId] ?? 0;
            $costTotal = $costsByMachine[$machineId] ?? 0;

            $machineCode = $usageData->machine_code ?? null;
            $machineName = $usageData->machine_name ?? null;
            $unit = $usageData->meter_unit ?? null;

            // Map meter_unit (HOURS/KM) to unit (HOUR/KM)
            if ($unit === 'HOURS') {
                $unit = 'HOUR';
            }

            // Get usage from work logs (primary source)
            $usageQty = $usageData ? (float) $usageData->usage_qty : 0;

            // Margin = revenue (income) - costs
            $margin = $revenueTotal - $costTotal;

            // Calculate per-unit values (null if usage_qty = 0)
            $costPerUnit = $usageQty > 0 ? ($costTotal / $usageQty) : null;
            $chargePerUnit = $usageQty > 0 ? ($revenueTotal / $usageQty) : null;
            $marginPerUnit = $usageQty > 0 ? ($margin / $usageQty) : null;

            // If we don't have machine info from usage, fetch it
            if (!$machineCode || !$machineName) {
                $machine = DB::table('machines')
                    ->where('id', $machineId)
                    ->where('tenant_id', $tenantId)
                    ->first(['code', 'name', 'meter_unit']);
                if ($machine) {
                    $machineCode = $machine->code;
                    $machineName = $machine->name;
                    if (!$unit) {
                        $unit = $machine->meter_unit === 'HOURS' ? 'HOUR' : ($machine->meter_unit === 'KM' ? 'KM' : null);
                    }
                }
            }

            $rows[] = [
                'machine_id' => $machineId,
                'machine_code' => $machineCode,
                'machine_name' => $machineName,
                'unit' => $unit,
                'usage_qty' => (string) round($usageQty, 2),
                'charges_total' => (string) round($revenueTotal, 2),
                'costs_total' => (string) round($costTotal, 2),
                'margin' => (string) round($margin, 2),
                'cost_per_unit' => $costPerUnit !== null ? (string) round($costPerUnit, 2) : null,
                'charge_per_unit' => $chargePerUnit !== null ? (string) round($chargePerUnit, 2) : null,
                'margin_per_unit' => $marginPerUnit !== null ? (string) round($marginPerUnit, 2) : null,
            ];
        }

        // Sort by machine_code
        usort($rows, fn($a, $b) => strcmp($a['machine_code'] ?? '', $b['machine_code'] ?? ''));

        return response()->json($rows);
    }
}
