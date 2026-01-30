<?php

namespace App\Services;

use App\Exceptions\CropCycleCloseException;
use App\Models\CropCycle;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Settlement;
use Carbon\Carbon;

class CropCycleCloseService
{
    private const RECONCILIATION_TOLERANCE = 0.01;

    public function __construct(
        private ReconciliationService $reconciliationService,
        private SettlementService $settlementService
    ) {}

    /**
     * Preview close: returns checklist (status, has_posted_settlement, reconciliation_summary, reconciliation, blocking_reasons).
     * Does not mutate anything.
     *
     * @return array{status: string, has_posted_settlement: bool, reconciliation_summary: array{pass: int, warn: int, fail: int, checks?: array}, reconciliation: array{from: string, to: string, counts: array{pass: int, warn: int, fail: int}, checks: array}, blocking_reasons: string[]}
     */
    public function previewClose(string $cropCycleId, string $tenantId): array
    {
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
        $blockingReasons = [];

        if ($cycle->status !== 'OPEN') {
            $blockingReasons[] = 'Cycle is not OPEN.';
        }

        $hasPostedSettlement = Settlement::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->exists();

        if (!$hasPostedSettlement) {
            $blockingReasons[] = 'At least one POSTED settlement is required for this crop cycle.';
        }

        // Optional: all projects not ACTIVE
        $activeCount = Project::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->count();
        if ($activeCount > 0) {
            $blockingReasons[] = 'One or more projects are still ACTIVE.';
        }

        [$from, $to] = $this->getReconciliationDateRange($cycle, $cropCycleId, $tenantId);
        $reconciliationSummary = $this->buildReconciliationSummary($cropCycleId, $tenantId, $from, $to);

        $reconciliation = [
            'from' => $from,
            'to' => $to,
            'counts' => [
                'pass' => $reconciliationSummary['pass'],
                'warn' => $reconciliationSummary['warn'],
                'fail' => $reconciliationSummary['fail'],
            ],
            'checks' => $reconciliationSummary['checks'] ?? [],
        ];

        return [
            'status' => $cycle->status,
            'has_posted_settlement' => $hasPostedSettlement,
            'reconciliation_summary' => $reconciliationSummary,
            'reconciliation' => $reconciliation,
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * Close the crop cycle. Throws CropCycleCloseException if preconditions fail.
     */
    public function close(string $cropCycleId, string $tenantId, ?string $userId = null, ?string $note = null): CropCycle
    {
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();

        if ($cycle->status !== 'OPEN') {
            throw new CropCycleCloseException('Crop cycle is not OPEN. Cannot close.');
        }

        $hasPostedSettlement = Settlement::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'POSTED')
            ->exists();
        if (!$hasPostedSettlement) {
            throw new CropCycleCloseException('At least one POSTED settlement is required for this crop cycle.');
        }

        $activeCount = Project::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->count();
        if ($activeCount > 0) {
            throw new CropCycleCloseException('One or more projects are still ACTIVE. Close or complete projects first.');
        }

        if (config('accounting.close_cycle_block_on_fail', true)) {
            [$from, $to] = $this->getReconciliationDateRange($cycle, $cropCycleId, $tenantId);
            $reconciliationSummary = $this->buildReconciliationSummary($cropCycleId, $tenantId, $from, $to);
            $failCount = $reconciliationSummary['fail'] ?? 0;
            if ($failCount > 0) {
                throw new CropCycleCloseException('Reconciliation has failures. Resolve before closing.');
            }
        }

        $cycle->update([
            'status' => 'CLOSED',
            'closed_at' => now(),
            'closed_by_user_id' => $userId,
            'close_note' => $note,
        ]);

        return $cycle->fresh();
    }

    /**
     * Reopen the crop cycle (admin). Leaves closed_at/closed_by_user_id/close_note for audit.
     */
    public function reopen(string $cropCycleId, string $tenantId): CropCycle
    {
        $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();

        if ($cycle->status !== 'CLOSED') {
            throw new CropCycleCloseException('Crop cycle is not CLOSED. Cannot reopen.');
        }

        $cycle->update(['status' => 'OPEN']);

        return $cycle->fresh();
    }

    /**
     * Get from/to date range for reconciliation: prefer cycle start_date/end_date; fallback to min/max posting_date.
     *
     * @return array{0: string, 1: string}
     */
    private function getReconciliationDateRange(CropCycle $cycle, string $cropCycleId, string $tenantId): array
    {
        $from = $cycle->start_date ? $cycle->start_date->format('Y-m-d') : null;
        $to = $cycle->end_date ? $cycle->end_date->format('Y-m-d') : null;

        if ($from === null || $to === null) {
            $range = PostingGroup::where('crop_cycle_id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->selectRaw('MIN(posting_date) as min_date, MAX(posting_date) as max_date')
                ->first();
            $from = $from ?? ($range && $range->min_date ? Carbon::parse($range->min_date)->format('Y-m-d') : '2000-01-01');
            $to = $to ?? ($range && $range->max_date ? Carbon::parse($range->max_date)->format('Y-m-d') : Carbon::today()->format('Y-m-d'));
        }

        return [$from, $to];
    }

    /**
     * Build reconciliation summary (pass/warn/fail counts) for the cycle. Soft gate — does not block close.
     * Checks include key, title, status, summary to match dashboard shape.
     *
     * @return array{pass: int, warn: int, fail: int, checks: array}
     */
    private function buildReconciliationSummary(string $cropCycleId, string $tenantId, string $from, string $to): array
    {
        $projectIds = Project::where('crop_cycle_id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->toArray();

        if (empty($projectIds)) {
            $checks = [
                ['key' => 'crop_cycle_scope', 'title' => 'Crop cycle scope', 'status' => 'WARN', 'summary' => 'No projects in this crop cycle'],
            ];
            return ['pass' => 0, 'warn' => 1, 'fail' => 0, 'checks' => $checks];
        }

        $checks = [];
        $settlementRevenue = 0.0;
        $settlementExpenses = 0.0;
        foreach ($projectIds as $pid) {
            try {
                $pool = $this->settlementService->getProjectProfitFromLedgerExcludingCOGS($pid, $tenantId, $to);
                $settlementRevenue += (float) $pool['total_revenue'];
                $settlementExpenses += (float) $pool['total_expenses'];
            } catch (\Throwable $e) {
                $checks[] = ['key' => 'settlement_vs_ot', 'title' => 'Settlement vs OT (crop cycle)', 'status' => 'FAIL', 'summary' => $e->getMessage()];
                return [
                    'pass' => 0,
                    'warn' => 0,
                    'fail' => 1,
                    'checks' => $checks,
                ];
            }
        }

        try {
            $ot = $this->reconciliationService->reconcileCropCycleSettlementVsOT($cropCycleId, $tenantId, $from, $to);
            $revenueDelta = $settlementRevenue - $ot['ot_revenue'];
            $expensesDelta = $settlementExpenses - $ot['ot_expenses_total'];
            $pass = abs($revenueDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = [
                'key' => 'settlement_vs_ot',
                'title' => 'Settlement vs OT (crop cycle)',
                'status' => $pass ? 'PASS' : 'FAIL',
                'summary' => $pass ? 'Delta: Rs 0' : sprintf('Revenue delta: %.2f; Expenses delta: %.2f', $revenueDelta, $expensesDelta),
            ];
        } catch (\Throwable $e) {
            $checks[] = ['key' => 'settlement_vs_ot', 'title' => 'Settlement vs OT (crop cycle)', 'status' => 'FAIL', 'summary' => $e->getMessage()];
        }

        try {
            $ledger = $this->reconciliationService->reconcileCropCycleLedgerIncomeExpense($cropCycleId, $tenantId, $from, $to, true);
            $ot = $this->reconciliationService->reconcileCropCycleSettlementVsOT($cropCycleId, $tenantId, $from, $to);
            $incomeDelta = $ledger['ledger_income'] - $ot['ot_revenue'];
            $expensesDelta = $ledger['ledger_expenses'] - $ot['ot_expenses_total'];
            $pass = abs($incomeDelta) < self::RECONCILIATION_TOLERANCE && abs($expensesDelta) < self::RECONCILIATION_TOLERANCE;
            $checks[] = [
                'key' => 'ledger_vs_ot',
                'title' => 'Ledger vs OT (crop cycle)',
                'status' => $pass ? 'PASS' : 'FAIL',
                'summary' => $pass ? 'Delta: Rs 0' : sprintf('Income delta: %.2f; Expenses delta: %.2f', $incomeDelta, $expensesDelta),
            ];
        } catch (\Throwable $e) {
            $checks[] = ['key' => 'ledger_vs_ot', 'title' => 'Ledger vs OT (crop cycle)', 'status' => 'FAIL', 'summary' => $e->getMessage()];
        }

        $pass = (int) collect($checks)->where('status', 'PASS')->count();
        $warn = (int) collect($checks)->where('status', 'WARN')->count();
        $fail = (int) collect($checks)->where('status', 'FAIL')->count();

        return [
            'pass' => $pass,
            'warn' => $warn,
            'fail' => $fail,
            'checks' => $checks,
        ];
    }
}
