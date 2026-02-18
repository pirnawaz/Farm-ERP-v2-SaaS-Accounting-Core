<?php

namespace App\Domains\Accounting\PeriodClose;

use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\PeriodCloseRun;
use App\Models\PostingGroup;
use App\Services\SystemAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Crop cycle period close v2: full closing entries.
 * Zeros all income/expense accounts for the period, clears via CURRENT_EARNINGS, rolls to RETAINED_EARNINGS.
 * One atomic PERIOD_CLOSE posting group. Idempotent: one close run per crop cycle.
 */
final class PeriodCloseService
{
    public const SOURCE_TYPE = 'PERIOD_CLOSE';
    public const ALLOCATION_TYPE = 'PERIOD_CLOSE';

    private const INCOME_TYPE = 'income';

    public function __construct(
        private SystemAccountService $accountService,
        private PeriodCloseCalculator $calculator,
        private PeriodCloseGuard $guard
    ) {}

    /**
     * Close crop cycle: full per-account closing entries, then roll to retained earnings.
     * Idempotent: if already closed, returns existing run.
     *
     * @param string|null $asOf Optional close date (defaults to crop_cycle.end_date or today)
     * @return array{crop_cycle: CropCycle, close_run: PeriodCloseRun, posting_group_id: string, net_profit: string, closed_at: string, closed_by_user_id: string|null}
     */
    public function closeCropCycle(string $tenantId, string $cropCycleId, ?string $userId = null, ?string $asOf = null): array
    {
        return DB::transaction(function () use ($tenantId, $cropCycleId, $userId, $asOf) {
            $existingRun = PeriodCloseRun::where('tenant_id', $tenantId)
                ->where('crop_cycle_id', $cropCycleId)
                ->first();

            if ($existingRun) {
                $cycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->firstOrFail();
                return [
                    'crop_cycle' => $cycle,
                    'close_run' => $existingRun,
                    'posting_group_id' => $existingRun->posting_group_id,
                    'net_profit' => (string) $existingRun->net_profit,
                    'closed_at' => $existingRun->closed_at?->toIso8601String() ?? '',
                    'closed_by_user_id' => $existingRun->closed_by_user_id,
                ];
            }

            $cycle = $this->guard->ensureCanClose($cropCycleId, $tenantId, true);

            $fromDate = $cycle->start_date
                ? $cycle->start_date->format('Y-m-d')
                : '2000-01-01';
            $toDate = $asOf
                ?? ($cycle->end_date ? $cycle->end_date->format('Y-m-d') : null)
                ?? Carbon::today()->format('Y-m-d');
            $toDate = Carbon::parse($toDate)->format('Y-m-d');

            $this->guard->validateCloseWindow($cycle, $fromDate, $toDate);

            $balances = $this->calculator->getIncomeExpenseAccountBalances($tenantId, $cropCycleId, $fromDate, $toDate);

            $incomeRows = [];
            $expenseRows = [];
            foreach ($balances as $row) {
                if (strtolower($row['account_type']) === self::INCOME_TYPE) {
                    $incomeRows[] = $row;
                } else {
                    $expenseRows[] = $row;
                }
            }

            $totalIncome = array_sum(array_column($incomeRows, 'net_amount'));
            $totalExpense = array_sum(array_column($expenseRows, 'net_amount'));
            $netProfit = round($totalIncome - $totalExpense, 2);
            $totalIncome = round($totalIncome, 2);
            $totalExpense = round($totalExpense, 2);

            $snapshotJson = [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_profit' => $netProfit,
                'accounts_closed' => [
                    'income' => count($incomeRows),
                    'expense' => count($expenseRows),
                ],
            ];

            $retainedEarnings = $this->accountService->getByCode($tenantId, 'RETAINED_EARNINGS');
            $currentEarnings = $this->accountService->getByCode($tenantId, 'CURRENT_EARNINGS');

            $closedAt = now();

            $run = PeriodCloseRun::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'posting_group_id' => null,
                'status' => PeriodCloseRun::STATUS_COMPLETED,
                'closed_at' => $closedAt,
                'closed_by_user_id' => $userId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'net_profit' => $netProfit,
                'snapshot_json' => $snapshotJson,
            ]);

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cropCycleId,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $run->id,
                'posting_date' => $toDate,
                'idempotency_key' => 'period_close:' . $cropCycleId,
            ]);

            $run->update(['posting_group_id' => $postingGroup->id]);

            // Step 2: Zero income accounts (debit each for net_amount) and expense accounts (credit each for net_amount)
            foreach ($incomeRows as $row) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $row['account_id'],
                    'debit_amount' => $row['net_amount'],
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
            }
            foreach ($expenseRows as $row) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $row['account_id'],
                    'debit_amount' => 0,
                    'credit_amount' => $row['net_amount'],
                    'currency_code' => 'GBP',
                ]);
            }

            // Step 3 & 4: Balance via CURRENT_EARNINGS, then roll to RETAINED_EARNINGS (CURRENT_EARNINGS nets to 0)
            if ($netProfit > 0) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $currentEarnings->id,
                    'debit_amount' => 0,
                    'credit_amount' => $netProfit,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $currentEarnings->id,
                    'debit_amount' => $netProfit,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $retainedEarnings->id,
                    'debit_amount' => 0,
                    'credit_amount' => $netProfit,
                    'currency_code' => 'GBP',
                ]);
            } elseif ($netProfit < 0) {
                $absLoss = abs($netProfit);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $currentEarnings->id,
                    'debit_amount' => $absLoss,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $currentEarnings->id,
                    'debit_amount' => 0,
                    'credit_amount' => $absLoss,
                    'currency_code' => 'GBP',
                ]);
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $retainedEarnings->id,
                    'debit_amount' => $absLoss,
                    'credit_amount' => 0,
                    'currency_code' => 'GBP',
                ]);
            }

            $ruleSnapshot = [
                'crop_cycle_id' => $cropCycleId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_profit' => $netProfit,
                'count_income_accounts_closed' => count($incomeRows),
                'count_expense_accounts_closed' => count($expenseRows),
                'retained_earnings_account_id' => $retainedEarnings->id,
                'current_earnings_account_id' => $currentEarnings->id,
            ];

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => null,
                'party_id' => null,
                'allocation_type' => self::ALLOCATION_TYPE,
                'allocation_scope' => null,
                'amount' => abs($netProfit),
                'rule_snapshot' => $ruleSnapshot,
            ]);

            $cycle->update([
                'status' => 'CLOSED',
                'closed_at' => $closedAt,
                'closed_by_user_id' => $userId,
            ]);

            return [
                'crop_cycle' => $cycle->fresh(),
                'close_run' => $run->fresh(),
                'posting_group_id' => $postingGroup->id,
                'net_profit' => (string) $run->net_profit,
                'closed_at' => $closedAt->toIso8601String(),
                'closed_by_user_id' => $userId,
            ];
        });
    }

    /**
     * Get the period close run for a crop cycle if it exists.
     */
    public function getCloseRun(string $tenantId, string $cropCycleId): ?PeriodCloseRun
    {
        return PeriodCloseRun::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $cropCycleId)
            ->first();
    }
}
