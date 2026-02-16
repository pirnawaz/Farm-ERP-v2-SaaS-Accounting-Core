<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Enforces accounting period locking: blocks posting into CLOSED periods.
 * Policy 1 for reversals: allow reversal when reversal_date is in the same closed period as original (net effect zero).
 */
class PostingDateGuard
{
    public function __construct(
        private AccountingPeriodService $periodService
    ) {}

    /**
     * Assert that posting is allowed for the given date (new posting, not reversal).
     * If no period exists, AccountingPeriodService will auto-create (Option B) or throw.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 409 if date falls in a CLOSED period
     */
    public function assertPostingDateAllowed(string $tenantId, Carbon $postingDate): void
    {
        $dateStr = $postingDate->format('Y-m-d');
        $period = $this->periodService->getOrCreatePeriodForDate($tenantId, $dateStr);
        if ($period->status === \App\Models\AccountingPeriod::STATUS_CLOSED) {
            abort(409, 'Posting is not allowed: the accounting period for ' . $dateStr . ' is closed.');
        }
    }

    /**
     * Assert that a reversal posting on reversalDate is allowed.
     * Policy 1: If original posting was in a CLOSED period and reversal_date equals original_posting_date, allow (reversal within same period).
     * Otherwise: reversal_date must not fall in a CLOSED period (or must be in same period as original).
     *
     * @param string $tenantId
     * @param string $originalPostingDate Y-m-d
     * @param string $reversalPostingDate Y-m-d
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 409 if not allowed
     */
    public function assertReversalDateAllowed(string $tenantId, string $originalPostingDate, string $reversalPostingDate): void
    {
        $periodOriginal = $this->periodService->getPeriodForDate($tenantId, $originalPostingDate);
        $periodReversal = $this->periodService->getPeriodForDate($tenantId, $reversalPostingDate);

        // Same date as original: allow if original is in closed period (reversal in same period = net zero)
        if ($originalPostingDate === $reversalPostingDate) {
            if ($periodOriginal && $periodOriginal->status === \App\Models\AccountingPeriod::STATUS_CLOSED) {
                return; // allowed: reversing within same closed period
            }
            if ($periodOriginal && $periodOriginal->status === \App\Models\AccountingPeriod::STATUS_OPEN) {
                return; // allowed: period open
            }
            // No period for date: allow (or period service would have created one for reversal date)
            return;
        }

        // Different date: reversal date must be in an OPEN period (or no period and we allow creation)
        if ($periodReversal && $periodReversal->status === \App\Models\AccountingPeriod::STATUS_CLOSED) {
            abort(409, 'Reversal is not allowed: the accounting period for ' . $reversalPostingDate . ' is closed. Use the original posting date to reverse within the same period.');
        }
        // If no period for reversal date, getOrCreate will run when we actually post; here we only block closed.
    }
}
