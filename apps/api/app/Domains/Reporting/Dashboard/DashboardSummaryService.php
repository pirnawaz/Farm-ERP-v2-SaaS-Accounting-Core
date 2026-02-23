<?php

namespace App\Domains\Reporting\Dashboard;

use App\Domains\Accounting\Reports\FinancialStatementsService;
use App\Domains\Reporting\ProfitLossService;
use App\Models\AccountingPeriod;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\OperationalTransaction;
use App\Models\Project;
use App\Models\SettlementPack;
use Illuminate\Support\Facades\DB;

/**
 * Read-only dashboard summary: one payload for all role views.
 * All queries are tenant-scoped. No ledger writes.
 */
final class DashboardSummaryService
{
    public function __construct(
        private FinancialStatementsService $financialStatementsService,
        private ProfitLossService $profitLossService
    ) {}

    /**
     * @param array{scope_type?: string, scope_id?: string} $params
     * @return array{scope: array{type: string, id: string|null, label: string}, farm: array, money: array, profit: array, governance: array, alerts: array}
     */
    public function getSummary(string $tenantId, array $params = []): array
    {
        $scope = $this->resolveScope($tenantId, $params);
        $asOf = now()->format('Y-m-d');

        $farm = $this->farmMetrics($tenantId, $scope);
        $money = $this->moneyMetrics($tenantId, $asOf, $scope);
        $profit = $this->profitMetrics($tenantId, $scope, $asOf);
        $governance = $this->governanceMetrics($tenantId, $scope);
        $alerts = $this->buildAlerts($tenantId, $farm, $money, $governance);

        return [
            'scope' => $scope,
            'farm' => $farm,
            'money' => $money,
            'profit' => $profit,
            'governance' => $governance,
            'alerts' => $alerts,
        ];
    }

    /**
     * Resolve scope: crop_cycle | project | year. Default: active crop cycle or current year.
     * @return array{type: string, id: string|null, label: string}
     */
    private function resolveScope(string $tenantId, array $params): array
    {
        $scopeType = $params['scope_type'] ?? null;
        $scopeId = $params['scope_id'] ?? null;
        $year = (int) ($params['year'] ?? now()->year);

        if ($scopeType === 'crop_cycle' && $scopeId) {
            $cycle = CropCycle::where('tenant_id', $tenantId)->where('id', $scopeId)->first();
            if ($cycle) {
                return [
                    'type' => 'crop_cycle',
                    'id' => $cycle->id,
                    'label' => $cycle->name,
                ];
            }
        }

        if ($scopeType === 'project' && $scopeId) {
            $project = Project::where('tenant_id', $tenantId)->where('id', $scopeId)->first();
            if ($project) {
                return [
                    'type' => 'project',
                    'id' => $project->id,
                    'label' => $project->name,
                ];
            }
        }

        if ($scopeType === 'year') {
            return [
                'type' => 'year',
                'id' => (string) $year,
                'label' => (string) $year,
            ];
        }

        // Default: active crop cycle or current year
        $activeCycle = CropCycle::where('tenant_id', $tenantId)
            ->where('status', 'OPEN')
            ->orderBy('start_date', 'desc')
            ->first();

        if ($activeCycle) {
            return [
                'type' => 'crop_cycle',
                'id' => $activeCycle->id,
                'label' => $activeCycle->name,
            ];
        }

        return [
            'type' => 'year',
            'id' => (string) $year,
            'label' => (string) $year,
        ];
    }

    private function farmMetrics(string $tenantId, array $scope): array
    {
        $activeCyclesCount = CropCycle::where('tenant_id', $tenantId)->where('status', 'OPEN')->count();
        $openProjectsCount = Project::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count();

        $cycleId = ($scope['type'] === 'crop_cycle' && $scope['id']) ? $scope['id'] : null;
        $harvestsThisCycleCount = 0;
        if ($cycleId) {
            $harvestsThisCycleCount = Harvest::where('tenant_id', $tenantId)
                ->where('crop_cycle_id', $cycleId)
                ->count();
        }

        $unpostedCount = OperationalTransaction::where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->count();
        $unpostedCount += Harvest::where('tenant_id', $tenantId)->where('status', 'DRAFT')->count();

        return [
            'active_crop_cycles_count' => $activeCyclesCount,
            'open_projects_count' => $openProjectsCount,
            'harvests_this_cycle_count' => $harvestsThisCycleCount,
            'unposted_records_count' => (int) $unpostedCount,
        ];
    }

    private function moneyMetrics(string $tenantId, string $asOf, array $scope): array
    {
        $balances = $this->getAccountBalances($tenantId, $asOf);
        $cash = $this->balanceByCode($balances, 'CASH');
        $bank = $this->balanceByCode($balances, 'BANK');
        $ar = $this->balanceByCode($balances, 'AR');
        $advancesTotal = 0.0;
        foreach ($balances as $row) {
            if (str_starts_with((string) ($row['account_code'] ?? ''), 'ADVANCE_')) {
                $advancesTotal += (float) ($row['balance'] ?? 0);
            }
        }

        return [
            'cash_balance' => round($cash, 2),
            'bank_balance' => round($bank, 2),
            'receivables_total' => round($ar, 2),
            'advances_outstanding_total' => round($advancesTotal, 2),
        ];
    }

    private function getAccountBalances(string $tenantId, string $asOf): array
    {
        $sql = "
            SELECT a.code AS account_code, SUM(le.debit_amount - le.credit_amount) AS balance
            FROM ledger_entries le
            JOIN accounts a ON a.id = le.account_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id
            WHERE le.tenant_id = :tenant_id AND pg.posting_date <= :as_of
            GROUP BY a.id, a.code
        ";
        $rows = DB::select($sql, ['tenant_id' => $tenantId, 'as_of' => $asOf]);
        return array_map(fn ($r) => ['account_code' => $r->account_code, 'balance' => $r->balance], $rows);
    }

    private function balanceByCode(array $balances, string $code): float
    {
        foreach ($balances as $row) {
            if (($row['account_code'] ?? '') === $code) {
                return (float) ($row['balance'] ?? 0);
            }
        }
        return 0.0;
    }

    private function profitMetrics(string $tenantId, array $scope, string $asOf): array
    {
        $year = (int) substr($asOf, 0, 4);
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";

        $profitYtd = $this->financialStatementsService->netProfitForRange($tenantId, $yearStart, $asOf);

        $profitThisCycle = null;
        $bestProject = null;
        $costPerAcre = null;

        if ($scope['type'] === 'crop_cycle' && $scope['id']) {
            $cycle = CropCycle::where('tenant_id', $tenantId)->where('id', $scope['id'])->first();
            if ($cycle) {
                $from = $cycle->start_date->format('Y-m-d');
                $to = min($asOf, $cycle->end_date?->format('Y-m-d') ?? $asOf);
                $profitThisCycle = $this->financialStatementsService->netProfitForRange($tenantId, $from, $to);
            }
        }

        if ($scope['type'] === 'year') {
            $profitThisCycle = $profitYtd;
        }

        $bestProject = $this->bestProjectByProfit($tenantId, $scope, $asOf);

        return [
            'profit_this_cycle' => $profitThisCycle !== null ? round($profitThisCycle, 2) : null,
            'profit_ytd' => round($profitYtd, 2),
            'best_project' => $bestProject,
            'cost_per_acre' => $costPerAcre,
        ];
    }

    private function bestProjectByProfit(string $tenantId, array $scope, string $asOf): ?array
    {
        $projects = Project::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->get();
        if ($projects->isEmpty()) {
            return null;
        }
        $year = (int) substr($asOf, 0, 4);
        $yearStart = "{$year}-01-01";
        $best = null;
        $bestProfit = null;
        foreach ($projects as $project) {
            $from = $scope['type'] === 'crop_cycle' && $scope['id']
                ? (CropCycle::find($scope['id'])?->start_date?->format('Y-m-d') ?? $yearStart)
                : $yearStart;
            $to = $asOf;
            $pl = $this->profitLossService->getProfitLoss($tenantId, $from, $to, ['project_id' => $project->id]);
            $net = $pl['totals']['net_profit'] ?? 0;
            if ($bestProfit === null || $net > $bestProfit) {
                $bestProfit = $net;
                $best = ['project_id' => $project->id, 'name' => $project->name, 'profit' => round($net, 2)];
            }
        }
        return $best;
    }

    private function governanceMetrics(string $tenantId, array $scope): array
    {
        $settlementsPendingCount = SettlementPack::where('tenant_id', $tenantId)
            ->whereIn('status', [SettlementPack::STATUS_DRAFT, SettlementPack::STATUS_PENDING_APPROVAL])
            ->count();

        $cyclesClosedCount = CropCycle::where('tenant_id', $tenantId)->where('status', 'CLOSED')->count();

        $locksWarning = [];
        $closedPeriods = AccountingPeriod::where('tenant_id', $tenantId)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->orderBy('period_end', 'desc')
            ->limit(5)
            ->get();
        foreach ($closedPeriods as $p) {
            $locksWarning[] = [
                'type' => 'period_closed',
                'label' => $p->name ?? $p->period_start->format('Y-m-d') . ' to ' . $p->period_end->format('Y-m-d'),
                'date' => $p->period_end->format('Y-m-d'),
            ];
        }

        return [
            'settlements_pending_count' => $settlementsPendingCount,
            'cycles_closed_count' => $cyclesClosedCount,
            'locks_warning' => $locksWarning,
        ];
    }

    /**
     * @return list<array{severity: string, title: string, detail: string, action: array{label: string, to: string}}>
     */
    private function buildAlerts(string $tenantId, array $farm, array $money, array $governance): array
    {
        $alerts = [];
        if (($farm['unposted_records_count'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'warn',
                'title' => 'Unposted records',
                'detail' => (string) $farm['unposted_records_count'] . ' draft record(s) pending posting.',
                'action' => ['label' => 'View transactions', 'to' => '/app/transactions?status=DRAFT'],
            ];
        }
        if (($governance['settlements_pending_count'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'info',
                'title' => 'Settlements pending',
                'detail' => (string) $governance['settlements_pending_count'] . ' settlement pack(s) in draft or pending approval.',
                'action' => ['label' => 'View settlements', 'to' => '/app/settlement'],
            ];
        }
        if (!empty($governance['locks_warning'])) {
            $alerts[] = [
                'severity' => 'info',
                'title' => 'Closed periods',
                'detail' => count($governance['locks_warning']) . ' accounting period(s) closed.',
                'action' => ['label' => 'Accounting periods', 'to' => '/app/accounting/periods'],
            ];
        }
        return $alerts;
    }
}
