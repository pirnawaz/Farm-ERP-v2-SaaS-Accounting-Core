<?php

namespace App\Services;

use App\Models\PostingGroup;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only project profitability from posted ledger + allocations (Phase 7B.1).
 *
 * Revenue splits:
 * - sales: income from SALE posting groups (effective source, see below).
 * - machinery_income: net MACHINERY_SERVICE_INCOME from FIELD_JOB / MACHINERY_CHARGE / MACHINERY_SERVICE (excludes HARVEST so in-kind machinery netting is not double-counted).
 * - in_kind_income: net income from HARVEST posting groups (settlements + any other income lines on that PG).
 *
 * Costs split by system account code (see bucket constants). Unmapped expense codes fall into inputs.
 *
 * Posting groups are restricted to the same operational source types as {@see SettlementService}
 * plus active-only ({@see PostingGroup::applyActiveOn}) and posted source documents where applicable.
 */
class ProjectProfitabilityService
{
    /**
     * @see SettlementService::OPERATIONAL_SOURCE_TYPES
     */
    private const OPERATIONAL_SOURCE_TYPES = [
        'INVENTORY_ISSUE', 'INVENTORY_GRN', 'LABOUR_WORK_LOG', 'MACHINE_WORK_LOG',
        'MACHINE_MAINTENANCE_JOB', 'MACHINERY_CHARGE', 'MACHINERY_SERVICE', 'FIELD_JOB', 'CROP_ACTIVITY', 'OPERATIONAL',
        'SALE', 'HARVEST', 'REVERSAL',
    ];

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
     * @param  array{from?: string, to?: string, crop_cycle_id?: string|null}  $filters  posting_date on posting_groups (inclusive); optional crop_cycle_id narrows posting_groups
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
                    AND {$effSourceSql} IN ('FIELD_JOB', 'MACHINERY_CHARGE', 'MACHINERY_SERVICE')
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
     * @return list<string>
     */
    private function eligiblePostingGroupIds(string $projectId, string $tenantId, ?string $from, ?string $to, ?string $cropCycleId = null): array
    {
        $q = DB::table('posting_groups as pg')
            ->where('pg.tenant_id', $tenantId)
            ->whereIn('pg.source_type', self::OPERATIONAL_SOURCE_TYPES)
            ->whereExists(function ($sub) use ($projectId, $tenantId) {
                $sub->select(DB::raw(1))
                    ->from('allocation_rows as ar')
                    ->whereColumn('ar.posting_group_id', 'pg.id')
                    ->where('ar.tenant_id', $tenantId)
                    ->where('ar.project_id', $projectId);
            });

        if ($cropCycleId !== null && $cropCycleId !== '') {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }

        if ($from !== null && $from !== '') {
            $q->where('pg.posting_date', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $q->where('pg.posting_date', '<=', $to);
        }

        PostingGroup::applyActiveOn($q, 'pg');
        $this->applyPostedSourceDocumentFilter($q);

        return $q->orderBy('pg.id')->pluck('pg.id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * Only include posting groups whose operational source document is POSTED (Phase 3C / reporting integrity).
     * Reversal posting groups are always included (they negate an original posted group).
     */
    private function applyPostedSourceDocumentFilter(Builder $q): void
    {
        $q->where(function ($w) {
            $w->where('pg.source_type', 'REVERSAL')
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'HARVEST')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('harvests as h')
                                ->whereColumn('h.posting_group_id', 'pg.id')
                                ->where('h.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'FIELD_JOB')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('field_jobs as fj')
                                ->whereColumn('fj.posting_group_id', 'pg.id')
                                ->where('fj.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'SALE')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('sales as s')
                                ->whereColumn('s.posting_group_id', 'pg.id')
                                ->where('s.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'MACHINERY_CHARGE')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('machinery_charges as mc')
                                ->whereColumn('mc.posting_group_id', 'pg.id')
                                ->where('mc.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'MACHINERY_SERVICE')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('machinery_services as ms')
                                ->whereColumn('ms.posting_group_id', 'pg.id')
                                ->where('ms.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'LABOUR_WORK_LOG')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('lab_work_logs as lw')
                                ->whereColumn('lw.posting_group_id', 'pg.id')
                                ->where('lw.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'MACHINE_WORK_LOG')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('machine_work_logs as mwl')
                                ->whereColumn('mwl.posting_group_id', 'pg.id')
                                ->where('mwl.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'CROP_ACTIVITY')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('crop_activities as ca')
                                ->whereColumn('ca.posting_group_id', 'pg.id')
                                ->where('ca.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'INVENTORY_ISSUE')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('inv_issues as ii')
                                ->whereColumn('ii.posting_group_id', 'pg.id')
                                ->where('ii.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'INVENTORY_GRN')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('inv_grns as ig')
                                ->whereColumn('ig.posting_group_id', 'pg.id')
                                ->where('ig.status', 'POSTED');
                        });
                })
                ->orWhere(function ($w2) {
                    $w2->where('pg.source_type', 'MACHINE_MAINTENANCE_JOB')
                        ->whereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('machine_maintenance_jobs as mmj')
                                ->whereColumn('mmj.posting_group_id', 'pg.id')
                                ->where('mmj.status', 'POSTED');
                        });
                })
                ->orWhereNotIn('pg.source_type', [
                    'HARVEST', 'FIELD_JOB', 'SALE', 'MACHINERY_CHARGE', 'MACHINERY_SERVICE',
                    'LABOUR_WORK_LOG', 'MACHINE_WORK_LOG', 'CROP_ACTIVITY', 'INVENTORY_ISSUE', 'INVENTORY_GRN',
                    'MACHINE_MAINTENANCE_JOB',
                ]);
        });
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
