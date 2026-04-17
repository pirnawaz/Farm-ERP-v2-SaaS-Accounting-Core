<?php

namespace App\Services;

use App\Domains\Reporting\ProjectPLQueryService;
use Illuminate\Support\Facades\DB;

/**
 * Read-only project profitability from posted ledger + project-scoped allocation rows.
 *
 * **Eligibility (Phase 4.5):** Posting groups are included if they have at least one allocation row with
 * project_id set for this project — the same basis as ProjectPLQueryService / GET /api/reports/project-pl.
 * Includes project-scoped supplier invoices/bills, inventory, operations, etc.
 * Cost-center-only bills (no project on allocations) never appear here.
 *
 * Revenue splits:
 * - sales: income from SALE posting groups (effective source, see below).
 * - machinery_income: net MACHINERY_SERVICE_INCOME from FIELD_JOB / MACHINERY_CHARGE / MACHINERY_SERVICE.
 * - in_kind_income: net income from HARVEST posting groups.
 *
 * Costs split by system account code (see bucket constants). Unmapped expense codes roll into inputs so bucket sum matches total cost.
 */
class ProjectProfitabilityService
{
    public function __construct(
        private ProjectPLQueryService $projectPLQuery
    ) {}

    /** @var list<string> */
    private const INPUT_EXPENSE_CODES = [
        'INPUTS_EXPENSE', 'STOCK_VARIANCE', 'COGS_PRODUCE', 'LOAN_INTEREST_EXPENSE',
    ];

    /** @var list<string> */
    private const LABOUR_EXPENSE_CODES = [
        'LABOUR_EXPENSE', 'EXP_KAMDARI', 'EXP_HARI_ONLY',
    ];

    /** @var list<string> */
    private const MACHINERY_EXPENSE_CODES = [
        'MACHINERY_FUEL_EXPENSE', 'MACHINERY_OPERATOR_EXPENSE', 'MACHINERY_MAINTENANCE_EXPENSE',
        'MACHINERY_OTHER_EXPENSE', 'MACHINERY_SERVICE_EXPENSE', 'EXP_SHARED', 'EXP_FARM_OVERHEAD',
        'FIXED_ASSET_DEPRECIATION_EXPENSE', 'LOSS_ON_FIXED_ASSET_DISPOSAL',
    ];

    /** @var list<string> */
    private const LANDLORD_EXPENSE_CODES = [
        'EXP_LANDLORD_ONLY',
    ];

    /**
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $filters  posting_date on posting_groups (inclusive); optional crop_cycle_id matches project row (same as project-pl)
     * @return array{
     *   revenue: array{sales: float, machinery_income: float, in_kind_income: float},
     *   costs: array{inputs: float, labour: float, machinery: float, landlord: float},
     *   totals: array{revenue: float, cost: float, profit: float}
     * }
     */
    public function getProjectProfitability(string $projectId, string $tenantId, array $filters = []): array
    {
        $from = isset($filters['from']) ? (string) $filters['from'] : null;
        $to = isset($filters['to']) ? (string) $filters['to'] : null;
        $cropCycleId = isset($filters['crop_cycle_id']) ? (string) $filters['crop_cycle_id'] : null;
        if ($cropCycleId === '') {
            $cropCycleId = null;
        }

        $pgIds = $this->eligiblePostingGroupIds($projectId, $tenantId, $from, $to, $cropCycleId);
        if ($pgIds === []) {
            return $this->emptyResult();
        }

        $placeholders = implode(',', array_fill(0, count($pgIds), '?'));
        $bindings = array_merge([$tenantId], $pgIds);

        $effSourceSql = 'COALESCE(src.source_type, pg.source_type)';

        // Revenue buckets (income accounts only)
        $revSql = "
            SELECT
                SUM(CASE WHEN a.type = 'income' AND {$effSourceSql} = 'SALE'
                    THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                    ELSE 0 END) AS sales,
                SUM(CASE WHEN a.type = 'income' AND a.code = 'MACHINERY_SERVICE_INCOME'
                    AND {$effSourceSql} IN ('FIELD_JOB', 'MACHINERY_CHARGE', 'MACHINERY_SERVICE', 'MACHINE_WORK_LOG', 'MACHINERY_EXTERNAL_INCOME')
                    THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                    ELSE 0 END) AS machinery_income,
                SUM(CASE WHEN a.type = 'income' AND {$effSourceSql} = 'HARVEST'
                    THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                    ELSE 0 END) AS in_kind_income,
                SUM(CASE WHEN a.type = 'income'
                    THEN (COALESCE(le.credit_amount_base, le.credit_amount) - COALESCE(le.debit_amount_base, le.debit_amount))
                    ELSE 0 END) AS revenue_total
            FROM ledger_entries le
            INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            INNER JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            LEFT JOIN posting_groups src ON src.id = pg.reversal_of_posting_group_id
            WHERE le.tenant_id = ?
              AND le.posting_group_id IN ({$placeholders})
        ";

        $rev = DB::selectOne($revSql, $bindings);

        // Cost buckets (expense accounts)
        $costCaseInputs = $this->expenseCaseSum(self::INPUT_EXPENSE_CODES);
        $costCaseLabour = $this->expenseCaseSum(self::LABOUR_EXPENSE_CODES);
        $costCaseMachinery = $this->expenseCaseSum(self::MACHINERY_EXPENSE_CODES);
        $costCaseLandlord = $this->expenseCaseSum(self::LANDLORD_EXPENSE_CODES);

        $costSql = "
            SELECT
                {$costCaseInputs} AS inputs,
                {$costCaseLabour} AS labour,
                {$costCaseMachinery} AS machinery,
                {$costCaseLandlord} AS landlord,
                SUM(CASE WHEN a.type = 'expense'
                    THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                    ELSE 0 END) AS cost_total
            FROM ledger_entries le
            INNER JOIN accounts a ON a.id = le.account_id AND a.tenant_id = le.tenant_id
            WHERE le.tenant_id = ?
              AND le.posting_group_id IN ({$placeholders})
        ";

        $cost = DB::selectOne($costSql, $bindings);

        $sales = round((float) ($rev->sales ?? 0), 2);
        $machineryIncome = round((float) ($rev->machinery_income ?? 0), 2);
        $inKindIncome = round((float) ($rev->in_kind_income ?? 0), 2);
        $totalRevenue = round((float) ($rev->revenue_total ?? 0), 2);

        $inputs = round((float) ($cost->inputs ?? 0), 2);
        $labour = round((float) ($cost->labour ?? 0), 2);
        $machinery = round((float) ($cost->machinery ?? 0), 2);
        $landlord = round((float) ($cost->landlord ?? 0), 2);
        $totalCost = round((float) ($cost->cost_total ?? 0), 2);

        // Map any expense account not in the four lists into inputs so bucket sum matches total cost.
        $bucketSum = $inputs + $labour + $machinery + $landlord;
        if (abs($bucketSum - $totalCost) > 0.02) {
            $inputs = round($inputs + ($totalCost - $bucketSum), 2);
        }

        $profit = round($totalRevenue - $totalCost, 2);

        return [
            'revenue' => [
                'sales' => $sales,
                'machinery_income' => $machineryIncome,
                'in_kind_income' => $inKindIncome,
            ],
            'costs' => [
                'inputs' => $inputs,
                'labour' => $labour,
                'machinery' => $machinery,
                'landlord' => $landlord,
            ],
            'totals' => [
                'revenue' => $totalRevenue,
                'cost' => $totalCost,
                'profit' => $profit,
            ],
        ];
    }

    /**
     * Same posting-group set as {@see ProjectPLQueryService::getEligiblePostingGroupIdsForProject()}.
     *
     * @return list<string>
     */
    private function eligiblePostingGroupIds(string $projectId, string $tenantId, ?string $from, ?string $to, ?string $cropCycleId = null): array
    {
        return $this->projectPLQuery->getEligiblePostingGroupIdsForProject($tenantId, $projectId, $from, $to, $cropCycleId);
    }

    /**
     * @param  list<string>  $codes
     */
    private function expenseCaseSum(array $codes): string
    {
        $list = implode(',', array_map(static fn (string $c) => "'".$c."'", $codes));

        return "SUM(CASE WHEN a.type = 'expense' AND a.code IN ({$list})
            THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
            ELSE 0 END)";
    }

    /**
     * @return array{
     *   revenue: array{sales: float, machinery_income: float, in_kind_income: float},
     *   costs: array{inputs: float, labour: float, machinery: float, landlord: float},
     *   totals: array{revenue: float, cost: float, profit: float}
     * }
     */
    private function emptyResult(): array
    {
        $z = 0.0;

        return [
            'revenue' => [
                'sales' => $z,
                'machinery_income' => $z,
                'in_kind_income' => $z,
            ],
            'costs' => [
                'inputs' => $z,
                'labour' => $z,
                'machinery' => $z,
                'landlord' => $z,
            ],
            'totals' => [
                'revenue' => $z,
                'cost' => $z,
                'profit' => $z,
            ],
        ];
    }
}
