<?php

namespace App\Services;

use App\Models\LabWorkLog;
use App\Models\LabWorkerBalance;
use App\Models\LedgerEntry;
use App\Models\AllocationRow;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LabourPostingService
{
    public function __construct(
        private SystemAccountService $accountService,
        private ReversalService $reversalService
    ) {}

    /**
     * Post a work log. Idempotent via idempotency_key.
     * Dr LABOUR_EXPENSE, Cr WAGES_PAYABLE. Allocation to crop_cycle + project.
     *
     * @throws \Exception
     */
    public function postWorkLog(string $workLogId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? "lab_work_log:{$workLogId}:post";

        return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $key) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['ledgerEntries.account', 'allocationRows']);
            }

            $workLog = LabWorkLog::where('id', $workLogId)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();
            $workLog->load(['worker', 'cropCycle', 'project']);

            if (!$workLog->crop_cycle_id || !$workLog->project_id) {
                throw new \Exception('Crop cycle and project are required for posting a work log.');
            }

            $cropCycle = CropCycle::where('id', $workLog->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($cropCycle->status !== 'OPEN') {
                throw new \Exception('Cannot post work log: crop cycle is closed.');
            }

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            $project = Project::where('id', $workLog->project_id)->where('tenant_id', $tenantId)->firstOrFail();

            // Recompute amount (server-side)
            $amount = (float) $workLog->units * (float) $workLog->rate;
            $workLog->update(['amount' => (string) round($amount, 2)]);

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $workLog->crop_cycle_id,
                'source_type' => 'LABOUR_WORK_LOG',
                'source_id' => $workLog->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            $labourExpenseAccount = $this->accountService->getByCode($tenantId, 'LABOUR_EXPENSE');
            $wagesPayableAccount = $this->accountService->getByCode($tenantId, 'WAGES_PAYABLE');

            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $labourExpenseAccount->id,
                'debit_amount' => (string) $amount,
                'credit_amount' => 0,
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'account_id' => $wagesPayableAccount->id,
                'debit_amount' => 0,
                'credit_amount' => (string) $amount,
                'currency_code' => 'GBP',
            ]);

            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $workLog->project_id,
                'party_id' => $project->party_id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => (string) $amount,
                'rule_snapshot' => ['source' => 'lab_work_log'],
            ]);

            $balance = LabWorkerBalance::getOrCreate($tenantId, $workLog->worker_id);
            $balance->increment('payable_balance', $amount);

            $workLog->update(['status' => 'POSTED', 'posting_group_id' => $postingGroup->id, 'posting_date' => $postingDateObj]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }

    /**
     * Reverse a posted work log. Creates reversing posting group and decrements worker balance.
     *
     * @throws \Exception
     */
    public function reverseWorkLog(string $workLogId, string $tenantId, string $postingDate, string $reason): PostingGroup
    {
        return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $reason) {
            $workLog = LabWorkLog::where('id', $workLogId)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$workLog->isPosted()) {
                throw new \Exception('Only posted work logs can be reversed.');
            }
            if ($workLog->isReversed()) {
                throw new \Exception('Work log is already reversed.');
            }

            $reversalPG = $this->reversalService->reversePostingGroup(
                $workLog->posting_group_id,
                $tenantId,
                $postingDate,
                $reason
            );

            $balance = LabWorkerBalance::where('tenant_id', $tenantId)
                ->where('worker_id', $workLog->worker_id)
                ->first();
            if ($balance) {
                $balance->decrement('payable_balance', (float) $workLog->amount);
            }

            $workLog->update(['status' => 'REVERSED']);

            return $reversalPG;
        });
    }
}
