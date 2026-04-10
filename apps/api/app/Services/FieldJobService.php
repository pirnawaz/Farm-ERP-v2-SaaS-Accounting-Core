<?php

namespace App\Services;

use App\Models\CropActivityType;
use App\Models\FieldJob;
use App\Models\FieldJobInput;
use App\Models\FieldJobLabour;
use App\Models\FieldJobMachine;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\LabWorker;
use App\Models\LandParcel;
use App\Models\Machine;
use App\Models\MachineryCharge;
use App\Models\MachineWorkLog;
use App\Models\ProductionUnit;
use App\Models\Project;
use Illuminate\Http\Request;

class FieldJobService
{
    /**
     * Eager-load graph for API responses (single document for frontend).
     */
    public function documentWith(): array
    {
        return [
            'project',
            'cropCycle',
            'productionUnit',
            'landParcel',
            'cropActivityType',
            'inputs.item',
            'inputs.store',
            'labour.worker',
            'machines.machine',
            'machines.sourceWorkLog',
            'machines.sourceCharge',
        ];
    }

    public function index(Request $request, string $tenantId)
    {
        $query = FieldJob::where('tenant_id', $tenantId)
            ->with($this->documentWith());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('from')) {
            $query->where('job_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('job_date', '<=', $request->to);
        }

        return $query->orderBy('job_date', 'desc')->orderBy('created_at', 'desc')->get();
    }

    public function show(string $id, string $tenantId): FieldJob
    {
        return FieldJob::where('id', $id)->where('tenant_id', $tenantId)
            ->with($this->documentWith())
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data  Validated request data
     */
    public function create(string $tenantId, array $data, ?string $createdByUserId): FieldJob
    {
        $project = Project::where('id', $data['project_id'])->where('tenant_id', $tenantId)->firstOrFail();
        $this->assertProjectOpenAndCycle($project);

        if (! empty($data['crop_activity_type_id'])) {
            CropActivityType::where('id', $data['crop_activity_type_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }
        if (! empty($data['production_unit_id'])) {
            ProductionUnit::where('id', $data['production_unit_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }
        if (! empty($data['land_parcel_id'])) {
            LandParcel::where('id', $data['land_parcel_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        return FieldJob::create([
            'tenant_id' => $tenantId,
            'doc_no' => $data['doc_no'] ?? null,
            'status' => 'DRAFT',
            'job_date' => $data['job_date'],
            'project_id' => $project->id,
            'crop_cycle_id' => $project->crop_cycle_id,
            'production_unit_id' => $data['production_unit_id'] ?? null,
            'land_parcel_id' => $data['land_parcel_id'] ?? null,
            'crop_activity_type_id' => $data['crop_activity_type_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $createdByUserId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(string $id, string $tenantId, array $data): FieldJob
    {
        $job = FieldJob::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        if (! $job->isDraft()) {
            abort(422, 'Only DRAFT field jobs can be updated.');
        }

        if (array_key_exists('project_id', $data) && $data['project_id'] !== null) {
            $project = Project::where('id', $data['project_id'])->where('tenant_id', $tenantId)->firstOrFail();
            $this->assertProjectOpenAndCycle($project);
            $job->project_id = $project->id;
            $job->crop_cycle_id = $project->crop_cycle_id;
        }

        if (array_key_exists('doc_no', $data)) {
            $job->doc_no = $data['doc_no'];
        }
        if (array_key_exists('job_date', $data)) {
            $job->job_date = $data['job_date'];
        }
        if (array_key_exists('crop_activity_type_id', $data)) {
            if ($data['crop_activity_type_id'] !== null && $data['crop_activity_type_id'] !== '') {
                CropActivityType::where('id', $data['crop_activity_type_id'])->where('tenant_id', $tenantId)->firstOrFail();
                $job->crop_activity_type_id = $data['crop_activity_type_id'];
            } else {
                $job->crop_activity_type_id = null;
            }
        }
        if (array_key_exists('production_unit_id', $data)) {
            if ($data['production_unit_id'] !== null && $data['production_unit_id'] !== '') {
                ProductionUnit::where('id', $data['production_unit_id'])->where('tenant_id', $tenantId)->firstOrFail();
                $job->production_unit_id = $data['production_unit_id'];
            } else {
                $job->production_unit_id = null;
            }
        }
        if (array_key_exists('land_parcel_id', $data)) {
            if ($data['land_parcel_id'] !== null && $data['land_parcel_id'] !== '') {
                LandParcel::where('id', $data['land_parcel_id'])->where('tenant_id', $tenantId)->firstOrFail();
                $job->land_parcel_id = $data['land_parcel_id'];
            } else {
                $job->land_parcel_id = null;
            }
        }
        if (array_key_exists('notes', $data)) {
            $job->notes = $data['notes'];
        }

        $job->save();

        return $job->fresh($this->documentWith());
    }

    public function addInput(string $fieldJobId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        InvStore::where('id', $data['store_id'])->where('tenant_id', $tenantId)->firstOrFail();
        InvItem::where('id', $data['item_id'])->where('tenant_id', $tenantId)->firstOrFail();

        FieldJobInput::create([
            'tenant_id' => $tenantId,
            'field_job_id' => $job->id,
            'store_id' => $data['store_id'],
            'item_id' => $data['item_id'],
            'qty' => $data['qty'],
        ]);

        return $job->fresh($this->documentWith());
    }

    public function updateInput(string $fieldJobId, string $lineId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobInput::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();

        if (isset($data['store_id'])) {
            InvStore::where('id', $data['store_id'])->where('tenant_id', $tenantId)->firstOrFail();
            $line->store_id = $data['store_id'];
        }
        if (isset($data['item_id'])) {
            InvItem::where('id', $data['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
            $line->item_id = $data['item_id'];
        }
        if (isset($data['qty'])) {
            $line->qty = $data['qty'];
        }
        if (array_key_exists('unit_cost_snapshot', $data)) {
            $line->unit_cost_snapshot = $data['unit_cost_snapshot'];
        }
        if (array_key_exists('line_total', $data)) {
            $line->line_total = $data['line_total'];
        }
        $line->save();

        return $job->fresh($this->documentWith());
    }

    public function deleteInput(string $fieldJobId, string $lineId, string $tenantId): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobInput::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();
        $line->delete();

        return $job->fresh($this->documentWith());
    }

    public function addLabour(string $fieldJobId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        LabWorker::where('id', $data['worker_id'])->where('tenant_id', $tenantId)->firstOrFail();

        FieldJobLabour::create([
            'tenant_id' => $tenantId,
            'field_job_id' => $job->id,
            'worker_id' => $data['worker_id'],
            'rate_basis' => $data['rate_basis'] ?? 'DAILY',
            'units' => $data['units'],
            'rate' => $data['rate'],
            'amount' => $data['amount'] ?? null,
        ]);

        return $job->fresh($this->documentWith());
    }

    public function updateLabour(string $fieldJobId, string $lineId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobLabour::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();

        if (isset($data['worker_id'])) {
            LabWorker::where('id', $data['worker_id'])->where('tenant_id', $tenantId)->firstOrFail();
            $line->worker_id = $data['worker_id'];
        }
        if (isset($data['rate_basis'])) {
            $line->rate_basis = $data['rate_basis'];
        }
        if (isset($data['units'])) {
            $line->units = $data['units'];
        }
        if (isset($data['rate'])) {
            $line->rate = $data['rate'];
        }
        if (array_key_exists('amount', $data)) {
            $line->amount = $data['amount'];
        }
        $line->save();

        return $job->fresh($this->documentWith());
    }

    public function deleteLabour(string $fieldJobId, string $lineId, string $tenantId): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobLabour::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();
        $line->delete();

        return $job->fresh($this->documentWith());
    }

    public function addMachine(string $fieldJobId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        Machine::where('id', $data['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();

        if (! empty($data['source_work_log_id'])) {
            MachineWorkLog::where('id', $data['source_work_log_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }
        if (! empty($data['source_charge_id'])) {
            MachineryCharge::where('id', $data['source_charge_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        FieldJobMachine::create([
            'tenant_id' => $tenantId,
            'field_job_id' => $job->id,
            'machine_id' => $data['machine_id'],
            'usage_qty' => $data['usage_qty'],
            'meter_unit_snapshot' => $data['meter_unit_snapshot'] ?? null,
            'rate_snapshot' => $data['rate_snapshot'] ?? null,
            'amount' => $data['amount'] ?? null,
            'source_work_log_id' => $data['source_work_log_id'] ?? null,
            'source_charge_id' => $data['source_charge_id'] ?? null,
        ]);

        return $job->fresh($this->documentWith());
    }

    public function updateMachine(string $fieldJobId, string $lineId, string $tenantId, array $data): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobMachine::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();

        if (isset($data['machine_id'])) {
            Machine::where('id', $data['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();
            $line->machine_id = $data['machine_id'];
        }
        if (isset($data['usage_qty'])) {
            $line->usage_qty = $data['usage_qty'];
        }
        if (array_key_exists('meter_unit_snapshot', $data)) {
            $line->meter_unit_snapshot = $data['meter_unit_snapshot'];
        }
        if (array_key_exists('rate_snapshot', $data)) {
            $line->rate_snapshot = $data['rate_snapshot'];
        }
        if (array_key_exists('amount', $data)) {
            $line->amount = $data['amount'];
        }
        if (array_key_exists('source_work_log_id', $data)) {
            if ($data['source_work_log_id'] !== null && $data['source_work_log_id'] !== '') {
                MachineWorkLog::where('id', $data['source_work_log_id'])->where('tenant_id', $tenantId)->firstOrFail();
            }
            $line->source_work_log_id = $data['source_work_log_id'] ?: null;
        }
        if (array_key_exists('source_charge_id', $data)) {
            if ($data['source_charge_id'] !== null && $data['source_charge_id'] !== '') {
                MachineryCharge::where('id', $data['source_charge_id'])->where('tenant_id', $tenantId)->firstOrFail();
            }
            $line->source_charge_id = $data['source_charge_id'] ?: null;
        }
        $line->save();

        return $job->fresh($this->documentWith());
    }

    public function deleteMachine(string $fieldJobId, string $lineId, string $tenantId): FieldJob
    {
        $job = $this->requireDraft($fieldJobId, $tenantId);
        $line = FieldJobMachine::where('id', $lineId)->where('field_job_id', $job->id)->where('tenant_id', $tenantId)->firstOrFail();
        $line->delete();

        return $job->fresh($this->documentWith());
    }

    private function requireDraft(string $fieldJobId, string $tenantId): FieldJob
    {
        $job = FieldJob::where('id', $fieldJobId)->where('tenant_id', $tenantId)->firstOrFail();
        if (! $job->isDraft()) {
            abort(422, 'Only DRAFT field jobs can be edited.');
        }

        return $job;
    }

    private function assertProjectOpenAndCycle(Project $project): void
    {
        if ($project->status === 'CLOSED') {
            abort(422, 'Project is closed.');
        }
        if ($project->crop_cycle_id === null) {
            abort(422, 'Project has no crop cycle.');
        }
    }
}
