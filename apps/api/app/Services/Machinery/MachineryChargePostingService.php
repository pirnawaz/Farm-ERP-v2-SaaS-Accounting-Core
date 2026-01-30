<?php

namespace App\Services\Machinery;

use App\Models\MachineryCharge;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Services\OperationalPostingGuard;
use App\Services\ReversalService;
use App\Services\SystemAccountService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineryChargePostingService
{
    private const SOURCE_TYPE = 'MACHINERY_CHARGE';

    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService,
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Post a machinery charge. Idempotent via idempotency_key or (source_type, source_id).
     * Creates PostingGroup with AllocationRow (MACHINERY_CHARGE) and balanced LedgerEntries.
     *
     * @throws \Exception
     */
    public function postCharge(string $chargeId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? 'machinery_charge:' . $chargeId . ':post';

        return DB::transaction(function () use ($chargeId, $tenantId, $postingDate, $key) {
            // Check idempotency by key
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Check idempotency by source
            $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $chargeId)
                ->first();
            if ($existingBySource) {
                return $existingBySource->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Load charge
            $charge = MachineryCharge::where('id', $chargeId)
                ->where('tenant_id', $tenantId)
                ->where('status', MachineryCharge::STATUS_DRAFT)
                ->with(['lines.workLog.machine', 'lines.rateCard', 'project', 'cropCycle', 'landlordParty'])
                ->firstOrFail();

            if (!$charge->crop_cycle_id || !$charge->project_id) {
                throw new \Exception('Crop cycle and project are required for posting a machinery charge.');
            }

            $this->guard->ensureCropCycleOpen($charge->crop_cycle_id, $tenantId);

            $cropCycle = CropCycle::where('id', $charge->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            // Get system accounts
            $expenseAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_SERVICE_EXPENSE');
            $liabilityAccount = $this->accountService->getByCode($tenantId, 'DUE_TO_LANDLORD');

            // Create PostingGroup
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $charge->crop_cycle_id,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $charge->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            // Prepare line summary for rule_snapshot
            $lineSummary = [];
            foreach ($charge->lines as $line) {
                $lineSummary[] = [
                    'line_id' => $line->id,
                    'work_log_id' => $line->machine_work_log_id,
                    'usage_qty' => (float) $line->usage_qty,
                    'unit' => $line->unit,
                    'rate' => (float) $line->rate,
                    'amount' => (float) $line->amount,
                    'rate_card_id' => $line->rate_card_id,
                ];
            }

            // Create AllocationRow (money allocation)
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $charge->project_id,
                'party_id' => $charge->landlord_party_id,
                'allocation_type' => 'MACHINERY_CHARGE',
                'amount' => (string) $charge->total_amount,
                'quantity' => null,
                'unit' => null,
                'rule_snapshot' => [
                    'source' => 'machinery_charge',
                    'machinery_charge_id' => $charge->id,
                    'pool_scope' => $charge->pool_scope,
                    'charge_date' => $charge->charge_date->format('Y-m-d'),
                    'lines' => $lineSummary,
                ],
            ]);

            // Create balanced LedgerEntries
            $amount = (float) $charge->total_amount;
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $expenseAccount->id,
                'debit_amount' => (string) $amount,
                'credit_amount' => '0.00',
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $liabilityAccount->id,
                'debit_amount' => '0.00',
                'credit_amount' => (string) $amount,
                'currency_code' => 'GBP',
            ]);

            // Update charge
            $charge->update([
                'status' => MachineryCharge::STATUS_POSTED,
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
            ]);

            return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted machinery charge. Uses ReversalService to reverse the posting group.
     *
     * @throws \Exception
     */
    public function reverseCharge(string $chargeId, string $tenantId, string $postingDate, ?string $reason = null): PostingGroup
    {
        $reason = $reason ?? 'Reversed';

        return DB::transaction(function () use ($chargeId, $tenantId, $postingDate, $reason) {
            // Load charge
            $charge = MachineryCharge::where('id', $chargeId)
                ->where('tenant_id', $tenantId)
                ->with(['postingGroup'])
                ->firstOrFail();

            if (!$charge->isPosted()) {
                throw new \Exception('Only posted machinery charges can be reversed.');
            }
            if ($charge->isReversed()) {
                throw new \Exception('Machinery charge is already reversed.');
            }

            $originalPostingGroup = $charge->postingGroup;
            if (!$originalPostingGroup) {
                throw new \Exception('Charge has no posting group to reverse.');
            }

            $this->guard->ensureCropCycleOpen($charge->crop_cycle_id, $tenantId);

            // Use ReversalService to reverse the posting group
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $originalPostingGroup->id,
                $tenantId,
                $postingDate,
                $reason
            );

            // ReversalService keeps the same amount for allocation rows, but for money allocations
            // (MACHINERY_CHARGE), we need to negate the amount to net to zero
            foreach ($reversalPostingGroup->allocationRows as $reversalAllocation) {
                if ($reversalAllocation->allocation_type === 'MACHINERY_CHARGE' && $reversalAllocation->amount !== null) {
                    $reversalAllocation->update([
                        'amount' => (string) (-(float) $reversalAllocation->amount),
                    ]);
                }
            }

            // Update charge
            $charge->update([
                'status' => MachineryCharge::STATUS_REVERSED,
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            // Note: We do NOT unset machinery_charge_id on work logs to keep the reservation intact

            return $reversalPostingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}
