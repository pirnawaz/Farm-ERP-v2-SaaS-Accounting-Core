<?php

namespace App\Domains\Reporting;

/**
 * Management farm P&amp;L: project-scoped posted P&amp;L + cost-center overhead, composed (no double-count).
 */
class FarmPnLSummaryReportingService
{
    public function __construct(
        private ProjectPLQueryService $projectPLQuery,
        private FarmOverheadReportingService $overheadReporting
    ) {}

    /**
     * @return array{
     *   period: array{from: string, to: string},
     *   crop_cycle_id: ?string,
     *   projects: array{
     *     rows: list<array{project_id: string, project_name: string|null, currency_code: string, income: string, expenses: string, net_profit: string}>,
     *     totals: array{currency_code: string, income: string, expenses: string, net_profit: string}
     *   },
     *   overhead: array{grand_totals: array{currency_code: string, income: string, expenses: string, net: string}, by_cost_center: list<mixed>},
     *   combined: array{net_farm_operating_result: string, currency_code: string}
     * }
     */
    public function getSummary(string $tenantId, string $from, string $to, ?string $cropCycleId = null): array
    {
        $projectRows = $this->projectPLQuery->getProjectPlRows($tenantId, $from, $to, null, $cropCycleId);
        $overhead = $this->overheadReporting->getOverheads($tenantId, $from, $to, null, null);

        $tIncome = 0.0;
        $tExpenses = 0.0;
        $tNet = 0.0;
        $ccy = 'GBP';
        foreach ($projectRows as $r) {
            $ccy = $r['currency_code'];
            $tIncome += (float) $r['income'];
            $tExpenses += (float) $r['expenses'];
            $tNet += (float) $r['net_profit'];
        }

        $ohNet = (float) $overhead['grand_totals']['net'];
        $farmNet = round($tNet + $ohNet, 2);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'crop_cycle_id' => $cropCycleId,
            'projects' => [
                'rows' => $projectRows,
                'totals' => [
                    'currency_code' => $ccy,
                    'income' => (string) round($tIncome, 2),
                    'expenses' => (string) round($tExpenses, 2),
                    'net_profit' => (string) round($tNet, 2),
                ],
            ],
            'overhead' => [
                'by_cost_center' => $overhead['by_cost_center'],
                'grand_totals' => $overhead['grand_totals'],
            ],
            'combined' => [
                'currency_code' => $ccy,
                'net_farm_operating_result' => (string) $farmNet,
            ],
        ];
    }
}
