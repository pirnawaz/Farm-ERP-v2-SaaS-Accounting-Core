<?php

namespace App\Services;

use App\Models\FieldJob;
use App\Models\FieldJobLabour;
use App\Models\FieldJobMachine;
use App\Models\Harvest;
use App\Models\HarvestShareLine;
use App\Models\LabWorkLog;
use App\Models\MachineryCharge;
use App\Models\MachineWorkLog;
use Illuminate\Validation\ValidationException;

/**
 * Prevents duplicate economic recognition across FieldJob, MachineryCharge, Harvest share lines, and LabWorkLog.
 *
 * @see docs/PHASE_4B_1_ENFORCEMENT_RULES_DESIGN.md
 */
class DuplicateWorkflowGuard
{
    private const MACH_USAGE_THRESHOLD = 0.001;

    /**
     * @throws ValidationException
     */
    public function assertFieldJobPostAllowed(FieldJob $fieldJob): void
    {
        $tenantId = $fieldJob->tenant_id;
        if (! $fieldJob->project_id || ! $fieldJob->crop_cycle_id) {
            return;
        }

        $fieldJob->loadMissing('machines');

        foreach ($fieldJob->machines as $line) {
            if ((float) $line->usage_qty < self::MACH_USAGE_THRESHOLD) {
                continue;
            }

            $machineId = $line->machine_id;

            if ($this->hasPostedInKindMachineHarvestForMachineScope(
                $tenantId,
                $fieldJob->project_id,
                $fieldJob->crop_cycle_id,
                $machineId
            )) {
                throw ValidationException::withMessages([
                    'duplicate_workflow' => ['This harvest already settles machine share for this crop and machine. Reverse the harvest posting or remove the in-kind machine share before posting this field job.'],
                ]);
            }

            if (! empty($line->source_work_log_id)) {
                $this->assertWorkLogNotFinanciallyCoveredByPostedChargeOrOtherFieldJob(
                    $tenantId,
                    (string) $line->source_work_log_id,
                    $fieldJob->id
                );
            }

            if (! empty($line->source_charge_id)) {
                $charge = MachineryCharge::where('id', $line->source_charge_id)->where('tenant_id', $tenantId)->first();
                if ($charge && $charge->isPosted()) {
                    throw ValidationException::withMessages([
                        'duplicate_workflow' => ['This machine usage is already accounted via Machinery Charge.'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, MachineWorkLog>  $workLogs
     *
     * @throws ValidationException
     */
    public function assertMachineryChargeDraftAllowed(string $tenantId, $workLogs): void
    {
        foreach ($workLogs as $workLog) {
            $this->assertWorkLogEligibleForMachineryCharge($tenantId, $workLog);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertMachineryChargePostAllowed(MachineryCharge $charge): void
    {
        $charge->loadMissing('lines.workLog');
        $tenantId = $charge->tenant_id;
        foreach ($charge->lines as $line) {
            if ($line->workLog) {
                $this->assertWorkLogEligibleForMachineryCharge($tenantId, $line->workLog);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertHarvestPostAllowed(Harvest $harvest): void
    {
        $harvest->loadMissing('shareLines');
        $tenantId = $harvest->tenant_id;
        if (! $harvest->project_id || ! $harvest->crop_cycle_id) {
            return;
        }

        foreach ($harvest->shareLines as $shareLine) {
            if (! $shareLine->isInKind()) {
                continue;
            }

            if ($shareLine->isMachine() && $shareLine->machine_id) {
                $this->assertNoPostedFieldJobMachineConflictForHarvestShare(
                    $tenantId,
                    $harvest,
                    (string) $shareLine->machine_id,
                    $shareLine
                );
                $this->assertNoDuplicateInKindMachineHarvest(
                    $tenantId,
                    $harvest,
                    (string) $shareLine->machine_id
                );
            }

            if ($shareLine->isLabour() && $shareLine->worker_id) {
                $this->assertNoPostedFieldJobLabourConflictForHarvestShare(
                    $tenantId,
                    $harvest,
                    (string) $shareLine->worker_id,
                    $shareLine
                );
                $this->assertNoDuplicateInKindLabourHarvest(
                    $tenantId,
                    $harvest,
                    (string) $shareLine->worker_id
                );
            }

            if ($shareLine->source_machinery_charge_id) {
                $this->assertMachineryChargeNotDoubleUsedInHarvest(
                    $tenantId,
                    $harvest,
                    (string) $shareLine->source_machinery_charge_id
                );
            }
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertLabWorkLogPostAllowed(LabWorkLog $workLog): void
    {
        if (! $workLog->project_id || ! $workLog->crop_cycle_id || ! $workLog->worker_id) {
            return;
        }

        $tenantId = $workLog->tenant_id;

        $existsFieldJobLabour = FieldJob::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $workLog->project_id)
            ->where('crop_cycle_id', $workLog->crop_cycle_id)
            ->where('status', 'POSTED')
            ->whereDate('job_date', $workLog->work_date->format('Y-m-d'))
            ->whereHas('labour', function ($q) use ($workLog) {
                $q->where('worker_id', $workLog->worker_id);
            })
            ->exists();

        if ($existsFieldJobLabour) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['Labour for this worker and day is already recorded via Field Job. Reverse the field job labour or use a different capture path.'],
            ]);
        }

        if ($this->postedInKindLabourHarvestBlocksLabWorkLog(
            $tenantId,
            $workLog
        )) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['This harvest already settles labour share for this worker. Reverse the harvest posting or adjust share lines before posting this work log.'],
            ]);
        }
    }

    private function assertWorkLogEligibleForMachineryCharge(string $tenantId, MachineWorkLog $workLog): void
    {
        $otherFj = FieldJobMachine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_work_log_id', $workLog->id)
            ->whereHas('fieldJob', fn ($q) => $q->where('status', 'POSTED'))
            ->first();

        if ($otherFj) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['This machine usage is already accounted via Field Job.'],
            ]);
        }

        if ($workLog->project_id && $workLog->crop_cycle_id && $workLog->machine_id) {
            if ($this->hasPostedInKindMachineHarvestForMachineScope(
                $tenantId,
                $workLog->project_id,
                $workLog->crop_cycle_id,
                $workLog->machine_id
            )) {
                throw ValidationException::withMessages([
                    'duplicate_workflow' => ['This harvest already settles machine share for this usage.'],
                ]);
            }
        }
    }

    private function assertWorkLogNotFinanciallyCoveredByPostedChargeOrOtherFieldJob(
        string $tenantId,
        string $workLogId,
        string $currentFieldJobId
    ): void {
        $wl = MachineWorkLog::where('id', $workLogId)->where('tenant_id', $tenantId)->first();
        if (! $wl) {
            return;
        }

        if ($wl->machinery_charge_id) {
            $charge = MachineryCharge::where('id', $wl->machinery_charge_id)->where('tenant_id', $tenantId)->first();
            if ($charge && $charge->isPosted()) {
                throw ValidationException::withMessages([
                    'duplicate_workflow' => ['This machine usage is already accounted via Machinery Charge.'],
                ]);
            }
        }

        $otherLine = FieldJobMachine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_work_log_id', $workLogId)
            ->where('field_job_id', '!=', $currentFieldJobId)
            ->whereHas('fieldJob', fn ($q) => $q->where('status', 'POSTED'))
            ->first();

        if ($otherLine) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['This machine usage is already accounted via Field Job.'],
            ]);
        }
    }

    private function hasPostedInKindMachineHarvestForMachineScope(
        string $tenantId,
        string $projectId,
        string $cropCycleId,
        string $machineId
    ): bool {
        return HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('recipient_role', HarvestShareLine::RECIPIENT_MACHINE)
            ->where('settlement_mode', HarvestShareLine::SETTLEMENT_IN_KIND)
            ->where('machine_id', $machineId)
            ->whereHas('harvest', function ($q) use ($tenantId, $projectId, $cropCycleId) {
                $q->where('tenant_id', $tenantId)
                    ->where('project_id', $projectId)
                    ->where('crop_cycle_id', $cropCycleId)
                    ->where('status', 'POSTED');
            })
            ->exists();
    }

    private function postedInKindLabourHarvestBlocksLabWorkLog(string $tenantId, LabWorkLog $workLog): bool
    {
        return HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('recipient_role', HarvestShareLine::RECIPIENT_LABOUR)
            ->where('settlement_mode', HarvestShareLine::SETTLEMENT_IN_KIND)
            ->where('worker_id', $workLog->worker_id)
            ->whereHas('harvest', function ($q) use ($tenantId, $workLog) {
                $q->where('tenant_id', $tenantId)
                    ->where('project_id', $workLog->project_id)
                    ->where('crop_cycle_id', $workLog->crop_cycle_id)
                    ->where('status', 'POSTED');
            })
            ->exists();
    }

    private function assertNoPostedFieldJobMachineConflictForHarvestShare(
        string $tenantId,
        Harvest $harvest,
        string $machineId,
        HarvestShareLine $shareLine
    ): void {
        $query = FieldJob::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $harvest->project_id)
            ->where('crop_cycle_id', $harvest->crop_cycle_id)
            ->where('status', 'POSTED')
            ->whereHas('machines', fn ($q) => $q->where('machine_id', $machineId));

        if ($shareLine->source_field_job_id) {
            $query->where('id', '!=', $shareLine->source_field_job_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['This machine usage is already accounted via Field Job. Remove or reverse the field job machinery lines, or adjust the harvest share before posting.'],
            ]);
        }
    }

    private function assertNoPostedFieldJobLabourConflictForHarvestShare(
        string $tenantId,
        Harvest $harvest,
        string $workerId,
        HarvestShareLine $shareLine
    ): void {
        $query = FieldJob::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $harvest->project_id)
            ->where('crop_cycle_id', $harvest->crop_cycle_id)
            ->where('status', 'POSTED')
            ->whereHas('labour', fn ($q) => $q->where('worker_id', $workerId));

        if ($shareLine->source_field_job_id) {
            $query->where('id', '!=', $shareLine->source_field_job_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['Labour for this worker is already recorded via Field Job. Remove or reverse the field job labour, or adjust the harvest share before posting.'],
            ]);
        }
    }

    private function assertNoDuplicateInKindMachineHarvest(
        string $tenantId,
        Harvest $harvest,
        string $machineId
    ): void {
        $exists = HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('recipient_role', HarvestShareLine::RECIPIENT_MACHINE)
            ->where('settlement_mode', HarvestShareLine::SETTLEMENT_IN_KIND)
            ->where('machine_id', $machineId)
            ->where('harvest_id', '!=', $harvest->id)
            ->whereHas('harvest', function ($q) use ($tenantId, $harvest) {
                $q->where('tenant_id', $tenantId)
                    ->where('project_id', $harvest->project_id)
                    ->where('crop_cycle_id', $harvest->crop_cycle_id)
                    ->where('status', 'POSTED');
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['Double settlement detected: another posted harvest already settles in-kind machine share for this machine and project.'],
            ]);
        }
    }

    private function assertNoDuplicateInKindLabourHarvest(
        string $tenantId,
        Harvest $harvest,
        string $workerId
    ): void {
        $exists = HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('recipient_role', HarvestShareLine::RECIPIENT_LABOUR)
            ->where('settlement_mode', HarvestShareLine::SETTLEMENT_IN_KIND)
            ->where('worker_id', $workerId)
            ->where('harvest_id', '!=', $harvest->id)
            ->whereHas('harvest', function ($q) use ($tenantId, $harvest) {
                $q->where('tenant_id', $tenantId)
                    ->where('project_id', $harvest->project_id)
                    ->where('crop_cycle_id', $harvest->crop_cycle_id)
                    ->where('status', 'POSTED');
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['Double settlement detected: another posted harvest already settles in-kind labour share for this worker and project.'],
            ]);
        }
    }

    private function assertMachineryChargeNotDoubleUsedInHarvest(
        string $tenantId,
        Harvest $harvest,
        string $chargeId
    ): void {
        $exists = HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_machinery_charge_id', $chargeId)
            ->where('harvest_id', '!=', $harvest->id)
            ->whereHas('harvest', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->where('status', 'POSTED');
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'duplicate_workflow' => ['Double settlement detected: this machinery charge is already referenced by another posted harvest share line.'],
            ]);
        }
    }
}
