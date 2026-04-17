<?php

namespace App\Services\Machinery;

use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\MachineWorkLog;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Services\LedgerWriteGuard;
use App\Services\OperationalPostingGuard;
use App\Services\Accounting\PostValidationService;
use App\Services\PostingDateGuard;
use App\Services\ReversalService;
use App\Services\SystemAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MachineryPostingService
{
    private const SOURCE_TYPE = 'MACHINE_WORK_LOG';

    public function __construct(
        private OperationalPostingGuard $guard,
        private SystemAccountService $accountService,
        private PostValidationService $postValidationService,
        private ReversalService $reversalService,
        private PostingDateGuard $postingDateGuard,
    ) {}

    /**
     * Post a machine work log. Idempotent via idempotency_key or (source_type, source_id).
     *
     * Non-chargeable (default): one AllocationRow MACHINERY_USAGE (quantity), no ledger entries (legacy).
     * Chargeable: Dr project expense (by pool scope) / Cr MACHINERY_SERVICE_INCOME plus one MACHINERY_SERVICE
     * allocation row (machine + project attribution), mirroring {@see MachineryServicePostingService}.
     */
    public function postWorkLog(string $workLogId, string $tenantId, string $postingDate, ?string $idempotencyKey = null): PostingGroup
    {
        $key = $idempotencyKey ?? 'machine_work_log:' . $workLogId . ':post';

        return LedgerWriteGuard::scoped(self::class, function () use ($workLogId, $tenantId, $postingDate, $key) {
            return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $key) {
                $existing = PostingGroup::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing->load(['allocationRows', 'ledgerEntries.account']);
                }

                $existingBySource = PostingGroup::where('tenant_id', $tenantId)
                    ->where('source_type', self::SOURCE_TYPE)
                    ->where('source_id', $workLogId)
                    ->first();
                if ($existingBySource) {
                    return $existingBySource->load(['allocationRows', 'ledgerEntries.account']);
                }

                $workLog = MachineWorkLog::where('id', $workLogId)
                    ->where('tenant_id', $tenantId)
                    ->where('status', MachineWorkLog::STATUS_DRAFT)
                    ->with(['machine', 'project', 'cropCycle'])
                    ->firstOrFail();

                if (! $workLog->crop_cycle_id || ! $workLog->project_id) {
                    throw new \Exception('Crop cycle and project are required for posting a machine work log.');
                }

                $this->guard->ensureCropCycleOpenForProject($workLog->project_id, $tenantId);

                $cropCycle = CropCycle::where('id', $workLog->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                if ($cropCycle->start_date && $postingDateObj < $cropCycle->start_date->format('Y-m-d')) {
                    throw new \Exception('Posting date is before crop cycle start date.');
                }
                if ($cropCycle->end_date && $postingDateObj > $cropCycle->end_date->format('Y-m-d')) {
                    throw new \Exception('Posting date is after crop cycle end date.');
                }

                if ($workLog->usage_qty < 0) {
                    throw new \Exception('Machine work log usage_qty must be greater than or equal to zero.');
                }

                $project = Project::where('id', $workLog->project_id)->where('tenant_id', $tenantId)->firstOrFail();
                if (! $project->party_id) {
                    throw new \Exception('Project must have a party_id for allocation_rows.');
                }

                $poolScope = $workLog->pool_scope ?? MachineWorkLog::POOL_SCOPE_SHARED;
                $allocationScope = $poolScope === MachineWorkLog::POOL_SCOPE_HARI_ONLY ? 'HARI_ONLY'
                    : ($poolScope === MachineWorkLog::POOL_SCOPE_LANDLORD_ONLY ? 'LANDLORD_ONLY' : 'SHARED');

                $postingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $workLog->crop_cycle_id,
                    'source_type' => self::SOURCE_TYPE,
                    'source_id' => $workLog->id,
                    'posting_date' => $postingDateObj,
                    'idempotency_key' => $key,
                ]);

                if ($workLog->chargeable) {
                    if ($workLog->internal_charge_rate === null || (float) $workLog->internal_charge_rate < 0) {
                        throw new \Exception('Chargeable work log requires internal_charge_rate >= 0.');
                    }
                    if ((float) $workLog->usage_qty <= 0) {
                        throw new \Exception('Chargeable work log requires usage_qty > 0.');
                    }

                    $amount = round((float) $workLog->internal_charge_rate * (float) $workLog->usage_qty, 2);
                    if ($amount <= 0) {
                        throw new \Exception('Calculated internal charge amount must be greater than zero.');
                    }

                    $expenseCode = match ($poolScope) {
                        MachineWorkLog::POOL_SCOPE_HARI_ONLY => 'EXP_HARI_ONLY',
                        MachineWorkLog::POOL_SCOPE_LANDLORD_ONLY => 'EXP_LANDLORD_ONLY',
                        default => 'EXP_SHARED',
                    };
                    $expenseAccount = $this->accountService->getByCode($tenantId, $expenseCode);
                    $incomeAccount = $this->accountService->getByCode($tenantId, 'MACHINERY_SERVICE_INCOME');

                    $ledgerLines = [
                        ['account_id' => $expenseAccount->id, 'debit_amount' => $amount, 'credit_amount' => 0.0],
                        ['account_id' => $incomeAccount->id, 'debit_amount' => 0.0, 'credit_amount' => $amount],
                    ];
                    $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

                    foreach ($ledgerLines as $row) {
                        LedgerEntry::create([
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $row['account_id'],
                            'debit_amount' => (string) $row['debit_amount'],
                            'credit_amount' => (string) $row['credit_amount'],
                            'currency_code' => 'GBP',
                        ]);
                    }

                    AllocationRow::create([
                        'tenant_id' => $tenantId,
                        'posting_group_id' => $postingGroup->id,
                        'project_id' => $workLog->project_id,
                        'party_id' => $project->party_id,
                        'allocation_type' => 'MACHINERY_SERVICE',
                        'allocation_scope' => $allocationScope,
                        'amount' => (string) $amount,
                        'quantity' => (string) $workLog->usage_qty,
                        'unit' => $workLog->machine->meter_unit,
                        'machine_id' => $workLog->machine_id,
                        'rule_snapshot' => [
                            'source' => 'machine_work_log_internal_charge',
                            'machine_work_log_id' => $workLog->id,
                            'internal_charge_rate' => (float) $workLog->internal_charge_rate,
                            'pool_scope' => $poolScope,
                            'meter_start' => $workLog->meter_start,
                            'meter_end' => $workLog->meter_end,
                        ],
                    ]);

                    $workLog->update([
                        'status' => MachineWorkLog::STATUS_POSTED,
                        'posting_group_id' => $postingGroup->id,
                        'posting_date' => $postingDateObj,
                        'posted_at' => now(),
                        'internal_charge_amount' => (string) $amount,
                    ]);

                    return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
                }

                AllocationRow::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'project_id' => $workLog->project_id,
                    'party_id' => $project->party_id,
                    'allocation_type' => 'MACHINERY_USAGE',
                    'allocation_scope' => $allocationScope,
                    'amount' => null,
                    'quantity' => (string) $workLog->usage_qty,
                    'unit' => $workLog->machine->meter_unit,
                    'rule_snapshot' => [
                        'source' => 'machine_work_log',
                        'machine_work_log_id' => $workLog->id,
                        'meter_start' => $workLog->meter_start,
                        'meter_end' => $workLog->meter_end,
                        'pool_scope' => $poolScope,
                    ],
                ]);

                $workLog->update([
                    'status' => MachineWorkLog::STATUS_POSTED,
                    'posting_group_id' => $postingGroup->id,
                    'posting_date' => $postingDateObj,
                    'posted_at' => now(),
                ]);

                return $postingGroup->fresh(['allocationRows', 'ledgerEntries.account']);
            });
        });
    }

    /**
     * Reverse a posted machine work log. Uses {@see ReversalService} when ledger lines exist; otherwise legacy quantity reversal.
     */
    public function reverseWorkLog(string $workLogId, string $tenantId, string $postingDate, ?string $reason = null): PostingGroup
    {
        $reason = $reason ?? 'Reversed';

        return LedgerWriteGuard::scoped(self::class, function () use ($workLogId, $tenantId, $postingDate, $reason) {
            return DB::transaction(function () use ($workLogId, $tenantId, $postingDate, $reason) {
                $workLog = MachineWorkLog::where('id', $workLogId)
                    ->where('tenant_id', $tenantId)
                    ->with(['machine', 'project', 'cropCycle', 'postingGroup.allocationRows', 'postingGroup.ledgerEntries'])
                    ->firstOrFail();

                if (! $workLog->isPosted()) {
                    throw new \Exception('Only posted machine work logs can be reversed.');
                }
                if ($workLog->isReversed()) {
                    throw new \Exception('Machine work log is already reversed.');
                }

                $originalPostingGroup = $workLog->postingGroup;
                if (! $originalPostingGroup) {
                    throw new \Exception('Work log has no posting group to reverse.');
                }

                $this->guard->ensureCropCycleOpenForProject($workLog->project_id, $tenantId);

                $postingDateObj = Carbon::parse($postingDate)->format('Y-m-d');
                $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateObj));

                if ($originalPostingGroup->ledgerEntries->isNotEmpty()) {
                    $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                        $originalPostingGroup->id,
                        $tenantId,
                        $postingDate,
                        $reason
                    );

                    $workLog->update([
                        'status' => MachineWorkLog::STATUS_REVERSED,
                        'reversal_posting_group_id' => $reversalPostingGroup->id,
                    ]);

                    return $reversalPostingGroup->load(['allocationRows', 'ledgerEntries.account']);
                }

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

                $reversalPostingGroup = PostingGroup::create([
                    'tenant_id' => $tenantId,
                    'crop_cycle_id' => $workLog->crop_cycle_id,
                    'source_type' => 'REVERSAL',
                    'source_id' => $originalPostingGroup->id,
                    'posting_date' => $postingDateObj,
                    'reversal_of_posting_group_id' => $originalPostingGroup->id,
                    'correction_reason' => $reason,
                ]);

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
        });
    }
}
