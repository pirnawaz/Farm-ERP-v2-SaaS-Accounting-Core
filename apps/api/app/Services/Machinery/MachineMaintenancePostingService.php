<?php

namespace App\Services\Machinery;

use App\Models\MachineMaintenanceJob;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\SystemAccountService;
use App\Services\ReversalService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineMaintenancePostingService
{
    private const SOURCE_TYPE = 'MACHINE_MAINTENANCE_JOB';

    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService
    ) {}

    /**
     * Post a maintenance job. Idempotent via idempotency_key or (source_type, source_id).
     * Creates PostingGroup with AllocationRow (MACHINERY_MAINTENANCE) and balanced LedgerEntries.
     * Note: Maintenance jobs do not require crop cycle validation.
     *
     * @throws \Exception
     */
    public function postJob(string $jobId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? 'machine_maintenance_job:' . $jobId . ':post';

        return DB::transaction(function () use ($jobId, $tenantId, $postingDate, $key) {
            // Check idempotency by key
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Check idempotency by source
            $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $jobId)
                ->first();
            if ($existingBySource) {
                return $existingBySource->load(['allocationRows', 'ledgerEntries.account']);
            }

            // Load job
            $job = MachineMaintenanceJob::where('id', $jobId)
                ->where('tenant_id', $tenantId)
                ->where('status', MachineMaintenanceJob::STATUS_DRAFT)
                ->with(['machine', 'maintenanceType', 'vendorParty', 'lines'])
                ->firstOrFail();

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            // Get system accounts
            $expenseAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_MAINTENANCE_EXPENSE');
            $liabilityAccount = $job->vendor_party_id
                ? $this->accountService->getByCode($tenantId, 'AP') // Accounts Payable
                : $this->accountService->getByCode($tenantId, 'ACCRUED_EXPENSES'); // Accrued Expenses

            // Create PostingGroup (no crop_cycle_id for maintenance)
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null, // Maintenance can be outside crop cycles
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $job->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            // Prepare line summary for rule_snapshot
            $lineSummary = [];
            foreach ($job->lines as $line) {
                $lineSummary[] = [
                    'line_id' => $line->id,
                    'description' => $line->description,
                    'amount' => (float) $line->amount,
                ];
            }

            // Create AllocationRow (money allocation with machine_id)
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => null, // Maintenance not tied to a project
                'party_id' => $job->vendor_party_id, // Vendor party if present
                'allocation_type' => 'MACHINERY_MAINTENANCE',
                'amount' => (string) $job->total_amount,
                'quantity' => null,
                'unit' => null,
                'machine_id' => $job->machine_id,
                'rule_snapshot' => [
                    'source' => 'machine_maintenance_job',
                    'machine_maintenance_job_id' => $job->id,
                    'job_no' => $job->job_no,
                    'job_date' => $job->job_date->format('Y-m-d'),
                    'maintenance_type_id' => $job->maintenance_type_id,
                    'vendor_party_id' => $job->vendor_party_id,
                    'lines' => $lineSummary,
                ],
            ]);

            // Create balanced LedgerEntries
            $amount = (float) $job->total_amount;
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

            // Update job
            $job->update([
                'status' => MachineMaintenanceJob::STATUS_POSTED,
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
                'posting_group_id' => $postingGroup->id,
            ]);

            return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }

    /**
     * Reverse a posted maintenance job. Uses ReversalService to reverse the posting group.
     *
     * @throws \Exception
     */
    public function reverseJob(string $jobId, string $tenantId, string $postingDate, ?string $reason = null): PostingGroup
    {
        $reason = $reason ?? 'Reversed';

        return DB::transaction(function () use ($jobId, $tenantId, $postingDate, $reason) {
            // Load job
            $job = MachineMaintenanceJob::where('id', $jobId)
                ->where('tenant_id', $tenantId)
                ->with(['postingGroup'])
                ->firstOrFail();

            if (!$job->isPosted()) {
                throw new \Exception('Only posted maintenance jobs can be reversed.');
            }
            if ($job->isReversed()) {
                throw new \Exception('Maintenance job is already reversed.');
            }

            $originalPostingGroup = $job->postingGroup;
            if (!$originalPostingGroup) {
                throw new \Exception('Job has no posting group to reverse.');
            }

            // Use ReversalService to reverse the posting group
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $originalPostingGroup->id,
                $tenantId,
                $postingDate,
                $reason
            );

            // ReversalService keeps the same amount for allocation rows, but for money allocations
            // (MACHINERY_MAINTENANCE), we need to negate the amount to net to zero
            foreach ($reversalPostingGroup->allocationRows as $reversalAllocation) {
                if ($reversalAllocation->allocation_type === 'MACHINERY_MAINTENANCE' && $reversalAllocation->amount !== null) {
                    $reversalAllocation->update([
                        'amount' => (string) (-(float) $reversalAllocation->amount),
                    ]);
                }
            }

            // Update job
            $job->update([
                'status' => MachineMaintenanceJob::STATUS_REVERSED,
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $reversalPostingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
        });
    }
}
