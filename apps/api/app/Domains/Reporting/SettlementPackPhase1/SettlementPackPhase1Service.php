<?php

namespace App\Domains\Reporting\SettlementPackPhase1;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\CropCycle;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Services\HarvestEconomicsService;
use App\Services\ProjectProfitabilityService;
use Illuminate\Support\Facades\DB;

class SettlementPackPhase1Service
{
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

    public function __construct(
        private SettlementPackPhase1RegisterQuery $registerQuery,
        private ProjectProfitabilityService $projectProfitabilityService,
        private HarvestEconomicsService $harvestEconomicsService,
    ) {}

    /**
     * @param  array{
     *   include_register: 'none'|'allocation'|'ledger'|'both',
     *   allocation_page: int,
     *   allocation_per_page: int,
     *   ledger_page: int,
     *   ledger_per_page: int,
     *   register_order: 'date_asc'|'date_desc',
     *   bucket: 'total'|'month'
     * }  $opts
     */
    public function buildProject(string $tenantId, string $projectId, string $from, string $to, array $opts): array
    {
        $project = Project::query()->where('tenant_id', $tenantId)->where('id', $projectId)->firstOrFail();
        $currencyCode = (string) (DB::table('tenants')->where('id', $tenantId)->value('currency_code') ?: 'GBP');
        $cropCycleId = $project->crop_cycle_id ? (string) $project->crop_cycle_id : null;

        $profit = $this->projectProfitabilityService->getProjectProfitability($projectId, $tenantId, [
            'from' => $from,
            'to' => $to,
            'crop_cycle_id' => $cropCycleId,
        ]);

        $costBuckets = $this->ledgerExpenseBucketsForProject($tenantId, $projectId, $from, $to, $cropCycleId);
        $inputs = $costBuckets['inputs'];
        $labour = $costBuckets['labour'];
        $machinery = $costBuckets['machinery'];
        $otherLedger = $costBuckets['other'];
        $totalLedgerCost = $costBuckets['total'];

        $creditPremium = $this->sumCreditPremium($tenantId, $from, $to, $projectId, $cropCycleId);
        $totalCost = round($inputs + $labour + $machinery + $otherLedger + $creditPremium, 2);

        $adv = $this->sumAdvances($tenantId, $from, $to, $projectId, $cropCycleId);

        $harvestAgg = $this->harvestEconomicsService->monthlyActualYieldByScope($tenantId, [
            'from' => $from,
            'to' => $to,
            'project_id' => $projectId,
            'crop_cycle_id' => $cropCycleId,
        ]);
        $harvestTotals = $harvestAgg['totals'] ?? ['actual_yield_qty' => 0.0, 'actual_yield_value' => 0.0];
        $hasHarvest = ((float) ($harvestTotals['actual_yield_qty'] ?? 0)) > 0.000001 || ((float) ($harvestTotals['actual_yield_value'] ?? 0)) > 0.000001;
        $harvestQty = $hasHarvest ? number_format((float) ($harvestTotals['actual_yield_qty'] ?? 0), 3, '.', '') : null;
        $harvestVal = $hasHarvest ? number_format((float) ($harvestTotals['actual_yield_value'] ?? 0), 2, '.', '') : null;

        $revSales = (float) ($profit['revenue']['sales'] ?? 0);
        $revMach = (float) ($profit['revenue']['machinery_income'] ?? 0);
        $revInKind = (float) ($profit['revenue']['in_kind_income'] ?? 0);
        $revTotal = (float) ($profit['totals']['revenue'] ?? 0);

        $netLedger = round($revTotal - $totalCost, 2);
        $netHarvest = $harvestVal === null ? null : number_format(((float) $harvestVal) - $totalCost, 2, '.', '');

        $payload = [
            'scope' => [
                'tenant_id' => $tenantId,
                'kind' => 'project',
                'project_id' => $projectId,
                'crop_cycle_id' => $cropCycleId,
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
                'posting_date_axis' => 'posting_groups.posting_date',
                'bucket' => $opts['bucket'],
            ],
            'currency_code' => strtoupper($currencyCode),
            'totals' => [
                'harvest_production' => [
                    'qty' => $harvestQty,
                    'value' => $harvestVal,
                ],
                'ledger_revenue' => [
                    'sales' => $this->money($revSales),
                    'machinery_income' => $this->money($revMach),
                    'in_kind_income' => $this->money($revInKind),
                    'total' => $this->money($revTotal),
                ],
                'costs' => [
                    'inputs' => $this->money($inputs),
                    'labour' => $this->money($labour),
                    'machinery' => $this->money($machinery),
                    'credit_premium' => $this->money($creditPremium),
                    'other' => $this->money($otherLedger),
                    'total' => $this->money($totalCost),
                ],
                'advances' => $adv,
                'net' => [
                    'net_ledger_result' => $this->money($netLedger),
                    'net_harvest_production_result' => $netHarvest,
                ],
            ],
            'series_by_month' => $opts['bucket'] === 'month'
                ? $this->seriesByMonth($tenantId, [
                    'from' => $from,
                    'to' => $to,
                    'project_id' => $projectId,
                    'crop_cycle_id' => $cropCycleId,
                ])
                : null,
            'register' => $this->buildRegistersProject($tenantId, $projectId, $from, $to, $opts),
            'exports' => $this->exportUrls('project', [
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
            ]),
            '_meta' => $this->meta(),
        ];

        if ($payload['series_by_month'] === null) {
            unset($payload['series_by_month']);
        }

        return $payload;
    }

    /**
     * @param  array{
     *   include_register: 'none'|'allocation'|'ledger'|'both',
     *   allocation_page: int,
     *   allocation_per_page: int,
     *   ledger_page: int,
     *   ledger_per_page: int,
     *   register_order: 'date_asc'|'date_desc',
     *   bucket: 'total'|'month',
     *   include_projects_breakdown: bool
     * }  $opts
     */
    public function buildCropCycle(string $tenantId, string $cropCycleId, string $from, string $to, array $opts): array
    {
        $cycle = CropCycle::query()->where('tenant_id', $tenantId)->where('id', $cropCycleId)->firstOrFail();
        $currencyCode = (string) (DB::table('tenants')->where('id', $tenantId)->value('currency_code') ?: 'GBP');

        $projects = Project::query()
            ->where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $cropCycleId)
            ->orderBy('name')
            ->get(['id', 'name']);
        $projectIds = $projects->pluck('id')->map(fn ($v) => (string) $v)->all();

        $sumRevenue = ['sales' => 0.0, 'machinery' => 0.0, 'in_kind' => 0.0, 'total' => 0.0];
        $sumCosts = ['inputs' => 0.0, 'labour' => 0.0, 'machinery' => 0.0, 'other' => 0.0, 'ledger_total' => 0.0];

        $perProject = [];
        foreach ($projects as $p) {
            $profit = $this->projectProfitabilityService->getProjectProfitability((string) $p->id, $tenantId, [
                'from' => $from,
                'to' => $to,
                'crop_cycle_id' => $cropCycleId,
            ]);

            $cb = $this->ledgerExpenseBucketsForProject($tenantId, (string) $p->id, $from, $to, $cropCycleId);
            $inputs = $cb['inputs'];
            $labour = $cb['labour'];
            $machinery = $cb['machinery'];
            $otherLedger = $cb['other'];
            $totalLedgerCost = $cb['total'];

            $revSales = (float) ($profit['revenue']['sales'] ?? 0);
            $revMach = (float) ($profit['revenue']['machinery_income'] ?? 0);
            $revInKind = (float) ($profit['revenue']['in_kind_income'] ?? 0);
            $revTotal = (float) ($profit['totals']['revenue'] ?? 0);

            $sumRevenue['sales'] += $revSales;
            $sumRevenue['machinery'] += $revMach;
            $sumRevenue['in_kind'] += $revInKind;
            $sumRevenue['total'] += $revTotal;

            $sumCosts['inputs'] += $inputs;
            $sumCosts['labour'] += $labour;
            $sumCosts['machinery'] += $machinery;
            $sumCosts['other'] += $otherLedger;
            $sumCosts['ledger_total'] += $totalLedgerCost;

            if (! empty($opts['include_projects_breakdown'])) {
                $perProject[] = [
                    'project_id' => (string) $p->id,
                    'project_name' => (string) $p->name,
                    'ledger_revenue_total' => $this->money($revTotal),
                    'inputs_cost' => $this->money($inputs),
                    'labour_cost' => $this->money($labour),
                    'machinery_cost' => $this->money($machinery),
                    'other_cost' => $this->money($otherLedger),
                    'ledger_cost_total' => $this->money($totalLedgerCost),
                ];
            }
        }

        $creditPremium = $this->sumCreditPremium($tenantId, $from, $to, null, $cropCycleId, $projectIds);
        $totalCost = round($sumCosts['inputs'] + $sumCosts['labour'] + $sumCosts['machinery'] + $sumCosts['other'] + $creditPremium, 2);

        $adv = $this->sumAdvances($tenantId, $from, $to, null, $cropCycleId, $projectIds);

        $harvestAgg = $this->harvestEconomicsService->monthlyActualYieldByScope($tenantId, [
            'from' => $from,
            'to' => $to,
            'crop_cycle_id' => $cropCycleId,
        ]);
        $harvestTotals = $harvestAgg['totals'] ?? ['actual_yield_qty' => 0.0, 'actual_yield_value' => 0.0];
        $hasHarvest = ((float) ($harvestTotals['actual_yield_qty'] ?? 0)) > 0.000001 || ((float) ($harvestTotals['actual_yield_value'] ?? 0)) > 0.000001;
        $harvestQty = $hasHarvest ? number_format((float) ($harvestTotals['actual_yield_qty'] ?? 0), 3, '.', '') : null;
        $harvestVal = $hasHarvest ? number_format((float) ($harvestTotals['actual_yield_value'] ?? 0), 2, '.', '') : null;

        $netLedger = round($sumRevenue['total'] - $totalCost, 2);
        $netHarvest = $harvestVal === null ? null : number_format(((float) $harvestVal) - $totalCost, 2, '.', '');

        $payload = [
            'scope' => [
                'tenant_id' => $tenantId,
                'kind' => 'crop_cycle',
                'crop_cycle_id' => $cropCycleId,
                'project_ids' => $projectIds,
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
                'posting_date_axis' => 'posting_groups.posting_date',
                'bucket' => $opts['bucket'],
            ],
            'currency_code' => strtoupper($currencyCode),
            'totals' => [
                'harvest_production' => [
                    'qty' => $harvestQty,
                    'value' => $harvestVal,
                ],
                'ledger_revenue' => [
                    'sales' => $this->money($sumRevenue['sales']),
                    'machinery_income' => $this->money($sumRevenue['machinery']),
                    'in_kind_income' => $this->money($sumRevenue['in_kind']),
                    'total' => $this->money($sumRevenue['total']),
                ],
                'costs' => [
                    'inputs' => $this->money($sumCosts['inputs']),
                    'labour' => $this->money($sumCosts['labour']),
                    'machinery' => $this->money($sumCosts['machinery']),
                    'credit_premium' => $this->money($creditPremium),
                    'other' => $this->money($sumCosts['other']),
                    'total' => $this->money($totalCost),
                ],
                'advances' => $adv,
                'net' => [
                    'net_ledger_result' => $this->money($netLedger),
                    'net_harvest_production_result' => $netHarvest,
                ],
            ],
            'series_by_month' => $opts['bucket'] === 'month'
                ? $this->seriesByMonth($tenantId, [
                    'from' => $from,
                    'to' => $to,
                    'crop_cycle_id' => $cropCycleId,
                    'project_ids' => $projectIds,
                ])
                : null,
            'register' => $this->buildRegistersCropCycle($tenantId, $cropCycleId, $projectIds, $from, $to, $opts),
            'exports' => $this->exportUrls('crop-cycle', [
                'crop_cycle_id' => $cropCycleId,
                'from' => $from,
                'to' => $to,
            ]),
            '_meta' => $this->meta(),
        ];

        if ($payload['series_by_month'] === null) {
            unset($payload['series_by_month']);
        }
        if (! empty($opts['include_projects_breakdown'])) {
            $payload['projects_breakdown'] = $perProject;
        }

        return $payload;
    }

    /**
     * Ledger-backed posted expense buckets for a project using code lists (no remapping).
     *
     * @return array{inputs: float, labour: float, machinery: float, other: float, total: float}
     */
    private function ledgerExpenseBucketsForProject(string $tenantId, string $projectId, string $from, string $to, ?string $cropCycleId): array
    {
        $inputsList = implode(',', array_map(static fn (string $c) => "'".$c."'", self::INPUT_EXPENSE_CODES));
        $labourList = implode(',', array_map(static fn (string $c) => "'".$c."'", self::LABOUR_EXPENSE_CODES));
        $machList = implode(',', array_map(static fn (string $c) => "'".$c."'", self::MACHINERY_EXPENSE_CODES));

        $q = DB::table('ledger_entries as le')
            ->join('accounts as a', function ($join) use ($tenantId) {
                $join->on('a.id', '=', 'le.account_id')->where('a.tenant_id', '=', $tenantId);
            })
            ->join('posting_groups as pg', function ($join) use ($tenantId) {
                $join->on('pg.id', '=', 'le.posting_group_id')->where('pg.tenant_id', '=', $tenantId);
            })
            ->where('le.tenant_id', $tenantId)
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to)
            ->whereExists(function ($sub) use ($tenantId, $projectId) {
                $sub->select(DB::raw(1))
                    ->from('allocation_rows as ar')
                    ->whereColumn('ar.posting_group_id', 'pg.id')
                    ->where('ar.tenant_id', $tenantId)
                    ->where('ar.project_id', $projectId);
            });
        PostingGroup::applyActiveOn($q, 'pg');

        if ($cropCycleId) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }

        $row = $q->selectRaw("
                COALESCE(SUM(CASE WHEN a.type = 'expense' AND a.code IN ({$inputsList})
                    THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                    ELSE 0 END), 0) AS inputs,
                COALESCE(SUM(CASE WHEN a.type = 'expense' AND a.code IN ({$labourList})
                    THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                    ELSE 0 END), 0) AS labour,
                COALESCE(SUM(CASE WHEN a.type = 'expense' AND a.code IN ({$machList})
                    THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                    ELSE 0 END), 0) AS machinery,
                COALESCE(SUM(CASE WHEN a.type = 'expense'
                    THEN (COALESCE(le.debit_amount_base, le.debit_amount) - COALESCE(le.credit_amount_base, le.credit_amount))
                    ELSE 0 END), 0) AS total
            ")->first();

        $inputs = round((float) ($row->inputs ?? 0), 2);
        $labour = round((float) ($row->labour ?? 0), 2);
        $machinery = round((float) ($row->machinery ?? 0), 2);
        $total = round((float) ($row->total ?? 0), 2);
        $other = round($total - ($inputs + $labour + $machinery), 2);

        return [
            'inputs' => $inputs,
            'labour' => $labour,
            'machinery' => $machinery,
            'other' => $other,
            'total' => $total,
        ];
    }

    private function buildRegistersProject(string $tenantId, string $projectId, string $from, string $to, array $opts): array
    {
        $include = $opts['include_register'];
        $order = $opts['register_order'];

        $out = [];
        if ($include === 'allocation' || $include === 'both') {
            $res = $this->registerQuery->allocationRegisterForProject(
                $tenantId,
                $projectId,
                $from,
                $to,
                $order,
                $opts['allocation_page'],
                $opts['allocation_per_page']
            );
            $out['allocation_rows'] = [
                'rows' => $res['rows'],
                'page' => $opts['allocation_page'],
                'per_page' => $opts['allocation_per_page'],
                'total_rows' => $res['total_rows'],
                'capped' => false,
            ];
        }
        if ($include === 'ledger' || $include === 'both') {
            $res = $this->registerQuery->ledgerAuditRegisterForProject(
                $tenantId,
                $projectId,
                $from,
                $to,
                $order,
                $opts['ledger_page'],
                $opts['ledger_per_page']
            );
            $out['ledger_lines'] = [
                'rows' => $res['rows'],
                'page' => $opts['ledger_page'],
                'per_page' => $opts['ledger_per_page'],
                'total_rows' => $res['total_rows'],
                'capped' => false,
            ];
        }

        return $out;
    }

    private function buildRegistersCropCycle(string $tenantId, string $cropCycleId, array $projectIds, string $from, string $to, array $opts): array
    {
        $include = $opts['include_register'];
        $order = $opts['register_order'];

        $out = [];
        if ($include === 'allocation' || $include === 'both') {
            $res = $this->registerQuery->allocationRegisterForCropCycle(
                $tenantId,
                $cropCycleId,
                $projectIds,
                $from,
                $to,
                $order,
                $opts['allocation_page'],
                $opts['allocation_per_page']
            );
            $out['allocation_rows'] = [
                'rows' => $res['rows'],
                'page' => $opts['allocation_page'],
                'per_page' => $opts['allocation_per_page'],
                'total_rows' => $res['total_rows'],
                'capped' => false,
            ];
        }
        if ($include === 'ledger' || $include === 'both') {
            $res = $this->registerQuery->ledgerAuditRegisterForCropCycle(
                $tenantId,
                $cropCycleId,
                $projectIds,
                $from,
                $to,
                $order,
                $opts['ledger_page'],
                $opts['ledger_per_page']
            );
            $out['ledger_lines'] = [
                'rows' => $res['rows'],
                'page' => $opts['ledger_page'],
                'per_page' => $opts['ledger_per_page'],
                'total_rows' => $res['total_rows'],
                'capped' => false,
            ];
        }

        return $out;
    }

    /**
     * @param  array{from: string, to: string, project_id?: string|null, crop_cycle_id?: string|null, project_ids?: list<string>}  $filters
     */
    private function seriesByMonth(string $tenantId, array $filters): array
    {
        $from = (string) $filters['from'];
        $to = (string) $filters['to'];
        $projectId = isset($filters['project_id']) ? (string) $filters['project_id'] : null;
        $cropCycleId = isset($filters['crop_cycle_id']) ? (string) $filters['crop_cycle_id'] : null;
        $projectIds = $filters['project_ids'] ?? null;

        $harvest = $this->harvestEconomicsService->monthlyActualYieldByScope($tenantId, [
            'from' => $from,
            'to' => $to,
            'project_id' => $projectId,
            'crop_cycle_id' => $cropCycleId,
        ]);
        $harvestByMonth = $harvest['by_month'] ?? [];
        $harvestSeries = [];
        foreach ($harvestByMonth as $m => $vals) {
            $harvestSeries[] = [
                'month' => (string) $m,
                'qty' => number_format((float) ($vals['actual_yield_qty'] ?? 0), 3, '.', ''),
                'value' => number_format((float) ($vals['actual_yield_value'] ?? 0), 2, '.', ''),
            ];
        }

        $premByMonth = $this->sumCreditPremiumByMonth($tenantId, $from, $to, $projectId, $cropCycleId, $projectIds);
        $premSeries = [];
        foreach ($premByMonth as $m => $amt) {
            $premSeries[] = ['month' => $m, 'amount' => $this->money($amt)];
        }

        // Profitability series: compute per month by reusing profitability service window by window.
        $months = $this->monthsInRange($from, $to);
        $profitabilitySeries = [];
        foreach ($months as $m) {
            $mFrom = max($from, $m['from']);
            $mTo = min($to, $m['to']);
            $revTotal = 0.0;
            $costTotal = 0.0;
            $inputs = 0.0;
            $labour = 0.0;
            $machinery = 0.0;
            $other = 0.0;

            if ($projectId) {
                $p = $this->projectProfitabilityService->getProjectProfitability($projectId, $tenantId, [
                    'from' => $mFrom,
                    'to' => $mTo,
                    'crop_cycle_id' => $cropCycleId,
                ]);
                $revTotal = (float) ($p['totals']['revenue'] ?? 0);
                $costTotal = (float) ($p['totals']['cost'] ?? 0);
                $inputs = (float) ($p['costs']['inputs'] ?? 0);
                $labour = (float) ($p['costs']['labour'] ?? 0);
                $machinery = (float) ($p['costs']['machinery'] ?? 0);
                $other = round($costTotal - ($inputs + $labour + $machinery), 2);
            } else {
                // crop cycle: sum project windows
                foreach (($projectIds ?? []) as $pid) {
                    $p = $this->projectProfitabilityService->getProjectProfitability($pid, $tenantId, [
                        'from' => $mFrom,
                        'to' => $mTo,
                        'crop_cycle_id' => $cropCycleId,
                    ]);
                    $revTotal += (float) ($p['totals']['revenue'] ?? 0);
                    $costTotal += (float) ($p['totals']['cost'] ?? 0);
                    $inputs += (float) ($p['costs']['inputs'] ?? 0);
                    $labour += (float) ($p['costs']['labour'] ?? 0);
                    $machinery += (float) ($p['costs']['machinery'] ?? 0);
                }
                $other = round($costTotal - ($inputs + $labour + $machinery), 2);
            }

            $profitabilitySeries[] = [
                'month' => $m['month'],
                'ledger_revenue_total' => $this->money($revTotal),
                'cost_total' => $this->money($costTotal),
                'inputs' => $this->money($inputs),
                'labour' => $this->money($labour),
                'machinery' => $this->money($machinery),
                'other' => $this->money($other),
            ];
        }

        return [
            'harvest_production' => $harvestSeries,
            'credit_premium' => $premSeries,
            'profitability' => $profitabilitySeries,
        ];
    }

    private function sumCreditPremium(string $tenantId, string $from, string $to, ?string $projectId, ?string $cropCycleId, ?array $projectIds = null): float
    {
        $q = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->join('supplier_invoices as si', function ($join) {
                $join->on('si.id', '=', 'pg.source_id')
                    ->where('pg.source_type', '=', 'SUPPLIER_INVOICE');
            })
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('ar.allocation_type', 'SUPPLIER_INVOICE_CREDIT_PREMIUM')
            ->whereIn('si.status', [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID])
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($q, 'pg');

        if ($cropCycleId) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }
        if ($projectId) {
            $q->where('ar.project_id', $projectId);
        }
        if ($projectIds !== null) {
            $q->whereIn('ar.project_id', $projectIds);
        }

        $row = $q->selectRaw('COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)::numeric), 0) as prem')->first();

        return round((float) ($row->prem ?? 0), 2);
    }

    /**
     * @return array<string, float> month => amount
     */
    private function sumCreditPremiumByMonth(string $tenantId, string $from, string $to, ?string $projectId, ?string $cropCycleId, ?array $projectIds = null): array
    {
        $q = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->join('supplier_invoices as si', function ($join) {
                $join->on('si.id', '=', 'pg.source_id')
                    ->where('pg.source_type', '=', 'SUPPLIER_INVOICE');
            })
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->where('ar.allocation_type', 'SUPPLIER_INVOICE_CREDIT_PREMIUM')
            ->whereIn('si.status', [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID])
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($q, 'pg');

        if ($cropCycleId) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }
        if ($projectId) {
            $q->where('ar.project_id', $projectId);
        }
        if ($projectIds !== null) {
            $q->whereIn('ar.project_id', $projectIds);
        }

        $rows = $q->selectRaw("to_char(pg.posting_date, 'YYYY-MM') as month, COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)::numeric), 0) as amt")
            ->groupByRaw("to_char(pg.posting_date, 'YYYY-MM')")
            ->orderByRaw("to_char(pg.posting_date, 'YYYY-MM')")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->month] = round((float) ($r->amt ?? 0), 2);
        }

        return $out;
    }

    private function sumAdvances(string $tenantId, string $from, string $to, ?string $projectId, ?string $cropCycleId, ?array $projectIds = null): array
    {
        $q = DB::table('allocation_rows as ar')
            ->join('posting_groups as pg', 'pg.id', '=', 'ar.posting_group_id')
            ->where('ar.tenant_id', $tenantId)
            ->where('pg.tenant_id', $tenantId)
            ->whereIn('ar.allocation_type', ['ADVANCE', 'ADVANCE_OFFSET'])
            ->whereDate('pg.posting_date', '>=', $from)
            ->whereDate('pg.posting_date', '<=', $to);
        PostingGroup::applyActiveOn($q, 'pg');

        if ($cropCycleId) {
            $q->where('pg.crop_cycle_id', $cropCycleId);
        }
        if ($projectId) {
            $q->where('ar.project_id', $projectId);
        }
        if ($projectIds !== null) {
            $q->whereIn('ar.project_id', $projectIds);
        }

        $rows = $q->selectRaw("
                ar.allocation_type,
                COALESCE(SUM(COALESCE(ar.amount_base, ar.amount)::numeric), 0) as amt
            ")
            ->groupBy('ar.allocation_type')
            ->get();

        $adv = 0.0;
        $rec = 0.0;
        foreach ($rows as $r) {
            $t = (string) $r->allocation_type;
            $a = round((float) ($r->amt ?? 0), 2);
            if ($t === 'ADVANCE') {
                $adv += $a;
            } elseif ($t === 'ADVANCE_OFFSET') {
                $rec += $a;
            }
        }
        $has = abs($adv) > 0.000001 || abs($rec) > 0.000001;
        if (! $has) {
            return ['advances' => null, 'recoveries' => null, 'net' => null];
        }

        return [
            'advances' => $this->money($adv),
            'recoveries' => $this->money($rec),
            'net' => $this->money($adv - $rec),
        ];
    }

    private function exportUrls(string $kind, array $params): array
    {
        $qp = http_build_query($params);
        if ($kind === 'project') {
            return [
                'csv' => [
                    'summary_url' => "/api/reports/settlement-pack/project/export/summary.csv?{$qp}",
                    'allocation_register_url' => "/api/reports/settlement-pack/project/export/allocation-register.csv?{$qp}",
                    'ledger_audit_register_url' => "/api/reports/settlement-pack/project/export/ledger-audit-register.csv?{$qp}",
                ],
                'pdf' => [
                    'url' => "/api/reports/settlement-pack/project/export/pack.pdf?{$qp}",
                ],
            ];
        }

        return [
            'csv' => [
                'summary_url' => "/api/reports/settlement-pack/crop-cycle/export/summary.csv?{$qp}",
                'allocation_register_url' => "/api/reports/settlement-pack/crop-cycle/export/allocation-register.csv?{$qp}",
                'ledger_audit_register_url' => "/api/reports/settlement-pack/crop-cycle/export/ledger-audit-register.csv?{$qp}",
            ],
            'pdf' => [
                'url' => "/api/reports/settlement-pack/crop-cycle/export/pack.pdf?{$qp}",
            ],
        ];
    }

    private function meta(): array
    {
        return [
            'generated_at_utc' => gmdate('c'),
            'active_posting_groups_only' => true,
            'excludes_reversals' => true,
            'notes' => [
                'Harvest production value is not sales revenue.',
            ],
            'net_definitions' => [
                'net_ledger_result' => 'ledger_revenue.total - costs.total',
                'net_harvest_production_result' => 'harvest_production.value - costs.total (null if no harvest production rows)',
            ],
            'cost_bucket_rules' => [
                'inputs_codes' => self::INPUT_EXPENSE_CODES,
                'labour_codes' => self::LABOUR_EXPENSE_CODES,
                'machinery_codes' => self::MACHINERY_EXPENSE_CODES,
                'credit_premium_allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
                'other_definition' => 'total_ledger_expenses - (inputs+labour+machinery)',
            ],
            'data_sources' => [
                'harvest_production' => 'HarvestEconomicsService::monthlyActualYieldByScope (allocation_rows HARVEST_PRODUCTION; posted harvests; posting_groups.posting_date axis)',
                'ledger_profitability' => 'ProjectProfitabilityService (ledger-backed eligible posting groups via allocation_rows)',
                'credit_premium' => 'allocation_rows(SUPPLIER_INVOICE_CREDIT_PREMIUM) joined to supplier_invoices(POSTED/PAID) via posting_groups',
                'advances' => 'allocation_rows(ADVANCE, ADVANCE_OFFSET) active posting groups',
                'registers' => 'allocation_rows and ledger_entries×allocation_rows audit join (active posting groups only)',
            ],
        ];
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /**
     * @return list<array{month: string, from: string, to: string}>
     */
    private function monthsInRange(string $from, string $to): array
    {
        $start = \Carbon\Carbon::parse($from)->startOfMonth();
        $end = \Carbon\Carbon::parse($to)->startOfMonth();

        $out = [];
        $cur = $start->copy();
        while ($cur <= $end) {
            $out[] = [
                'month' => $cur->format('Y-m'),
                'from' => $cur->copy()->startOfMonth()->toDateString(),
                'to' => $cur->copy()->endOfMonth()->toDateString(),
            ];
            $cur->addMonth();
        }

        return $out;
    }
}

