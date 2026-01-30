<?php

namespace App\Services;

use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Accounting\Rules\DailyBookEntryRuleResolver;
use App\Services\OperationalPostingGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReversalService
{
    public function __construct(
        private DailyBookEntryRuleResolver $ruleResolver = new DailyBookEntryRuleResolver(),
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Reverse a PostingGroup by creating a new PostingGroup with offsetting entries.
     * 
     * This is idempotent: if a reversal already exists for the same posting_date, returns it.
     * 
     * @param string $postingGroupId
     * @param string $tenantId
     * @param string $postingDate YYYY-MM-DD format
     * @param string $reason
     * @return PostingGroup
     * @throws \Exception
     */
    public function reversePostingGroup(
        string $postingGroupId,
        string $tenantId,
        string $postingDate,
        string $reason
    ): PostingGroup {
        return DB::transaction(function () use ($postingGroupId, $tenantId, $postingDate, $reason) {
            // Load original posting group (tenant-scoped)
            $originalPostingGroup = PostingGroup::where('id', $postingGroupId)
                ->where('tenant_id', $tenantId)
                ->with(['allocationRows', 'ledgerEntries'])
                ->firstOrFail();

            // Prevent reversing a reversal
            if ($originalPostingGroup->source_type === 'REVERSAL') {
                throw new \InvalidArgumentException('Cannot reverse a reversal posting group');
            }

            if ($originalPostingGroup->crop_cycle_id !== null) {
                $this->guard->ensureCropCycleOpen($originalPostingGroup->crop_cycle_id, $tenantId);
            }

            // Check if reversal already exists for this posting_date (idempotency)
            $existingReversal = PostingGroup::where('tenant_id', $tenantId)
                ->where('reversal_of_posting_group_id', $postingGroupId)
                ->where('posting_date', $postingDate)
                ->first();

            if ($existingReversal) {
                return $existingReversal->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Validate posting_date format
            $postingDateObj = \Carbon\Carbon::parse($postingDate);
            if (!$postingDateObj) {
                throw new \InvalidArgumentException('Invalid posting_date format. Expected YYYY-MM-DD');
            }

            // Create new PostingGroup for reversal (crop_cycle_id from original; null allowed for e.g. INVENTORY_GRN)
            $reversalPostingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $originalPostingGroup->crop_cycle_id,
                'source_type' => 'REVERSAL',
                'source_id' => $originalPostingGroup->id,
                'posting_date' => $postingDateObj->format('Y-m-d'),
                'reversal_of_posting_group_id' => $originalPostingGroup->id,
                'correction_reason' => $reason,
            ]);

            // Create AllocationRows that mirror the original (allocation_type, rule_snapshot with reversal info)
            foreach ($originalPostingGroup->allocationRows as $originalRow) {
                $reversalSnapshot = is_array($originalRow->rule_snapshot) ? $originalRow->rule_snapshot : [];
                $reversalSnapshot['reversal_of'] = $originalPostingGroup->id;
                $reversalSnapshot['reversal_reason'] = $reason;

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $reversalPostingGroup->id,
                    'project_id' => $originalRow->project_id,
                    'party_id' => $originalRow->party_id,
                    'allocation_type' => $originalRow->allocation_type,
                    'amount' => $originalRow->amount,
                    'rule_snapshot' => $reversalSnapshot,
                ]);
            }

            // Create LedgerEntries that exactly negate original (swap debit_amount and credit_amount)
            foreach ($originalPostingGroup->ledgerEntries as $originalEntry) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $reversalPostingGroup->id,
                    'account_id' => $originalEntry->account_id,
                    'debit_amount' => $originalEntry->credit_amount,
                    'credit_amount' => $originalEntry->debit_amount,
                    'currency_code' => $originalEntry->currency_code ?? 'GBP',
                ]);
            }

            // Reload reversal posting group with relationships
            return $reversalPostingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}
