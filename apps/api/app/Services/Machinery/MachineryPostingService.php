<?php

namespace App\Services\Machinery;

use App\Models\MachineWorkLog;
use App\Models\AllocationRow;
use App\Models\PostingGroup;
use App\Models\CropCycle;
use App\Models\Project;
use App\Services\OperationalPostingGuard;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MachineryPostingService
{
    private const SOURCE_TYPE = 'MACHINE_WORK_LOG';

    public function __construct(
        private OperationalPostingGuard $guard
    ) {}

    /**
     * Post a machine work log. Idempotent via idempotency_key or (source_type, source_id).
     * Creates one PostingGroup with one AllocationRow for usage (MACHINERY_USAGE), no LedgerEntries.
     *
     * @throws \Exception
     */
    public function postWorkLog(string $workLogId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? 'machine_work_log:' . $workLogId . ':post';

        return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $key) {
            $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->load(['allocationRows']);
            }

            $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $workLogId)
                ->first();
            if ($existingBySource) {
                return $existingBySource->load(['allocationRows']);
            }

            $workLog = MachineWorkLog::where('id', $workLogId)
                ->where('tenant_id', $tenantId)
                ->where('status', MachineWorkLog::STATUS_DRAFT)
                ->with(['machine', 'project', 'cropCycle'])
                ->firstOrFail();

            if (!$workLog->crop_cycle_id || !$workLog->project_id) {
                throw new \Exception('Crop cycle and project are required for posting a machine work log.');
            }

            $this->guard->ensureCropCycleOpen($workLog->crop_cycle_id, $tenantId);

            $cropCycle = CropCycle::where('id', $workLog->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
            if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                throw new \Exception('Posting date is before crop cycle start date.');
            }
            if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                throw new \Exception('Posting date is after crop cycle end date.');
            }

            // Validate usage_qty >= 0
            if ($workLog->usage_qty < 0) {
                throw new \Exception('Machine work log usage_qty must be greater than or equal to zero.');
            }

            $project = Project::where('id', $workLog->project_id)->where('tenant_id', $tenantId)->firstOrFail();
            if (!$project->party_id) {
                throw new \Exception('Project must have a party_id for allocation_rows.');
            }

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $workLog->crop_cycle_id,
                'source_type' => self::SOURCE_TYPE,
                'source_id' => $workLog->id,
                'posting_date' => $postingDateObj,
                'idempotency_key' => $key,
            ]);

            // Create exactly one AllocationRow for usage
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $postingGroup->id,
                'project_id' => $workLog->project_id,
                'party_id' => $project->party_id,
                'allocation_type' => 'MACHINERY_USAGE',
                'amount' => null,
                'quantity' => (string) $workLog->usage_qty,
                'unit' => $workLog->machine->meter_unit,
                'rule_snapshot' => [
                    'source' => 'machine_work_log',
                    'machine_work_log_id' => $workLog->id,
                    'meter_start' => $workLog->meter_start,
                    'meter_end' => $workLog->meter_end,
                    'pool_scope' => $workLog->pool_scope ?? MachineWorkLog::POOL_SCOPE_SHARED,
                ],
            ]);

            $workLog->update([
                'status' => MachineWorkLog::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posting_date' => $postingDateObj,
                'posted_at' => now(),
            ]);

            return $postingGroup->fresh(['allocationRows']);
        });
    }

    /**
     * Reverse a posted machine work log. Creates reversing PostingGroup with reversed allocation, no ledger entries.
     *
     * @throws \Exception
     */
    public function reverseWorkLog(string $workLogId, string $tenantId, string $postingDate, ?string $reason = null): PostingGroup
    {
        $reason = $reason ?? 'Reversed';

        return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $reason) {
            $workLog = MachineWorkLog::where('id', $workLogId)
                ->where('tenant_id', $tenantId)
                ->with(['machine', 'project', 'cropCycle', 'postingGroup.allocationRows'])
                ->firstOrFail();
            
            if (!$workLog->isPosted()) {
                throw new \Exception('Only posted machine work logs can be reversed.');
            }
            if ($workLog->isReversed()) {
                throw new \Exception('Machine work log is already reversed.');
            }

            $originalPostingGroup = $workLog->postingGroup;
            if (!$originalPostingGroup) {
                throw new \Exception('Work log has no posting group to reverse.');
            }

            $this->guard->ensureCropCycleOpen($workLog->crop_cycle_id, $tenantId);

            $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');

            // Check if reversal already exists (idempotency)
            $existingReversal = PostingGroup::where('tenant_id', $tenantId)
                ->where('reversal_of_posting_group_id', $originalPostingGroup->id)
                ->where('posting_date', $postingDateObj)
                ->first();

            if ($existingReversal) {
                $workLog->update([
                    'status' => MachineWorkLog::STATUS_REVERSED,
                    'reversal_posting_group_id' => $existingReversal->id,
                ]);
                return $existingReversal->load(['allocationRows']);
            }

            // Create reversal posting group
            $reversalPostingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $workLog->crop_cycle_id,
                'source_type' => 'REVERSAL',
                'source_id' => $originalPostingGroup->id,
                'posting_date' => $postingDateObj,
                'reversal_of_posting_group_id' => $originalPostingGroup->id,
                'correction_reason' => $reason,
            ]);

            // Create reversed allocation row (negative quantity, same unit/type/project/pool_scope)
            $originalAllocation = $originalPostingGroup->allocationRows->first();
            if ($originalAllocation) {
                $reversalSnapshot = is_array($originalAllocation->rule_snapshot) ? $originalAllocation->rule_snapshot : [];
                $reversalSnapshot['reversal_of'] = $originalPostingGroup->id;
                $reversalSnapshot['reversal_reason'] = $reason;

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $reversalPostingGroup->id,
                    'project_id' => $originalAllocation->project_id,
                    'party_id' => $originalAllocation->party_id,
                    'allocation_type' => $originalAllocation->allocation_type,
                    'amount' => null,
                    'quantity' => $originalAllocation->quantity ? (string) (-(float) $originalAllocation->quantity) : null,
                    'unit' => $originalAllocation->unit,
                    'rule_snapshot' => $reversalSnapshot,
                ]);
            }

            $workLog->update([
                'status' => MachineWorkLog::STATUS_REVERSED,
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);

            return $reversalPostingGroup->fresh(['allocationRows']);
        });
    }

}
