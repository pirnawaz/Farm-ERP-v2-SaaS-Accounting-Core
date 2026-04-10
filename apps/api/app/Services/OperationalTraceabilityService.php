<?php

namespace App\Services;

use App\Models\FieldJob;
use App\Models\FieldJobMachine;
use App\Models\Harvest;
use App\Models\HarvestShareLine;
use App\Models\MachineryCharge;
use App\Models\MachineWorkLog;

/**
 * Read-model summaries for cross-module links (field jobs, harvests, machinery).
 * No posting side effects.
 */
class OperationalTraceabilityService
{
    public function summarizeForFieldJob(FieldJob $job): array
    {
        $tenantId = $job->tenant_id;

        $harvestIds = HarvestShareLine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_field_job_id', $job->id)
            ->pluck('harvest_id')
            ->unique()
            ->filter()
            ->values();

        $linkedHarvests = $harvestIds->isEmpty()
            ? collect()
            : Harvest::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $harvestIds)
                ->orderBy('harvest_date')
                ->get(['id', 'harvest_no', 'harvest_date', 'status']);

        $machinerySources = [];
        foreach ($job->machines ?? [] as $m) {
            $row = [
                'field_job_machine_id' => $m->id,
                'machine_label' => optional($m->machine)->name ?? $m->machine_id,
            ];
            if ($m->source_work_log_id) {
                $wl = $m->relationLoaded('sourceWorkLog') ? $m->sourceWorkLog : null;
                $row['source_work_log'] = $wl ? $this->workLogSummary($wl) : ['id' => $m->source_work_log_id];
            }
            if ($m->source_charge_id) {
                $ch = $m->relationLoaded('sourceCharge') ? $m->sourceCharge : null;
                $row['source_machinery_charge'] = $ch ? $this->chargeSummary($ch) : ['id' => $m->source_charge_id];
            }
            $machinerySources[] = $row;
        }

        return [
            'linked_harvests' => $linkedHarvests->map(fn (Harvest $h) => $this->harvestSummary($h))->values()->all(),
            'machinery_sources' => $machinerySources,
        ];
    }

    public function summarizeForHarvest(Harvest $harvest): array
    {
        $tenantId = $harvest->tenant_id;

        $fieldJobIds = ($harvest->shareLines ?? collect())
            ->pluck('source_field_job_id')
            ->unique()
            ->filter()
            ->values();

        $linkedFieldJobs = $fieldJobIds->isEmpty()
            ? collect()
            : FieldJob::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $fieldJobIds)
                ->orderBy('job_date')
                ->get(['id', 'doc_no', 'job_date', 'status']);

        $shareLineSourceIds = ($harvest->shareLines ?? collect())->map(function ($sl) {
            /** @var HarvestShareLine $sl */
            return [
                'share_line_id' => $sl->id,
                'source_field_job_id' => $sl->source_field_job_id,
                'source_machinery_charge_id' => $sl->source_machinery_charge_id,
                'source_lab_work_log_id' => $sl->source_lab_work_log_id,
            ];
        })->values()->all();

        return [
            'linked_field_jobs' => $linkedFieldJobs->map(fn (FieldJob $fj) => $this->fieldJobSummary($fj))->values()->all(),
            'share_line_source_ids' => $shareLineSourceIds,
            'share_lines_count' => count($shareLineSourceIds),
        ];
    }

    public function summarizeForMachineryCharge(MachineryCharge $charge): array
    {
        $tenantId = $charge->tenant_id;

        $workLogsById = [];
        foreach ($charge->lines ?? [] as $line) {
            if (! $line->machine_work_log_id) {
                continue;
            }
            $wl = $line->relationLoaded('workLog') ? $line->workLog : null;
            if ($wl) {
                $workLogsById[$wl->id] = $this->workLogSummary($wl);
            } else {
                $workLogsById[$line->machine_work_log_id] = ['id' => $line->machine_work_log_id];
            }
        }

        $fieldJobLinks = FieldJobMachine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_charge_id', $charge->id)
            ->with(['fieldJob'])
            ->get()
            ->map(function (FieldJobMachine $fjm) {
                $fj = $fjm->fieldJob;

                return [
                    'field_job_machine_id' => $fjm->id,
                    'field_job' => $fj ? $this->fieldJobSummary($fj) : null,
                ];
            })
            ->values()
            ->all();

        return [
            'source_machine_work_logs' => array_values($workLogsById),
            'linked_field_job_machines' => $fieldJobLinks,
        ];
    }

    public function summarizeForMachineWorkLog(MachineWorkLog $log): array
    {
        $tenantId = $log->tenant_id;

        $fieldJobLinks = FieldJobMachine::query()
            ->where('tenant_id', $tenantId)
            ->where('source_work_log_id', $log->id)
            ->with(['fieldJob'])
            ->get()
            ->map(function (FieldJobMachine $fjm) {
                $fj = $fjm->fieldJob;

                return [
                    'field_job_machine_id' => $fjm->id,
                    'field_job' => $fj ? $this->fieldJobSummary($fj) : null,
                ];
            })
            ->values()
            ->all();

        $parentCharge = null;
        if ($log->machinery_charge_id) {
            $c = $log->relationLoaded('machineryCharge') ? $log->machineryCharge : MachineryCharge::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $log->machinery_charge_id)
                ->first();
            if ($c) {
                $parentCharge = $this->chargeSummary($c);
            }
        }

        return [
            'linked_field_job_machines' => $fieldJobLinks,
            'parent_machinery_charge' => $parentCharge,
        ];
    }

    /**
     * @return array{id: string, harvest_no: ?string, harvest_date: ?string, status: string}
     */
    private function harvestSummary(Harvest $h): array
    {
        return [
            'id' => $h->id,
            'harvest_no' => $h->harvest_no,
            'harvest_date' => $h->harvest_date?->format('Y-m-d'),
            'status' => $h->status,
        ];
    }

    /**
     * @return array{id: string, doc_no: ?string, job_date: ?string, status: string}
     */
    private function fieldJobSummary(FieldJob $fj): array
    {
        return [
            'id' => $fj->id,
            'doc_no' => $fj->doc_no,
            'job_date' => $fj->job_date?->format('Y-m-d'),
            'status' => $fj->status,
        ];
    }

    /**
     * @return array{id: string, work_log_no: ?string, work_date: ?string, status: string}
     */
    private function workLogSummary(MachineWorkLog $w): array
    {
        return [
            'id' => $w->id,
            'work_log_no' => $w->work_log_no,
            'work_date' => $w->work_date?->format('Y-m-d'),
            'status' => $w->status,
        ];
    }

    /**
     * @return array{id: string, charge_no: ?string, charge_date: ?string, status: string}
     */
    private function chargeSummary(MachineryCharge $c): array
    {
        return [
            'id' => $c->id,
            'charge_no' => $c->charge_no,
            'charge_date' => $c->charge_date?->format('Y-m-d'),
            'status' => $c->status,
        ];
    }
}
