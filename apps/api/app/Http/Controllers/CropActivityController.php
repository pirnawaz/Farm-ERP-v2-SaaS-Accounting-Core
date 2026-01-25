<?php

namespace App\Http\Controllers;

use App\Models\CropActivity;
use App\Models\CropActivityInput;
use App\Models\CropActivityLabour;
use App\Models\CropActivityType;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\InvStore;
use App\Models\InvItem;
use App\Models\LabWorker;
use App\Models\LandParcel;
use App\Http\Requests\StoreCropActivityRequest;
use App\Http\Requests\UpdateCropActivityRequest;
use App\Http\Requests\PostCropActivityRequest;
use App\Http\Requests\ReverseCropActivityRequest;
use App\Services\TenantContext;
use App\Services\CropActivityPostingService;
use Illuminate\Http\Request;

class CropActivityController extends Controller
{
    public function __construct(
        private CropActivityPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = CropActivity::where('tenant_id', $tenantId)
            ->with(['type', 'cropCycle', 'project', 'landParcel', 'inputs.item', 'inputs.store', 'labour.worker', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('activity_type_id')) {
            $query->where('activity_type_id', $request->activity_type_id);
        }
        if ($request->filled('land_parcel_id')) {
            $query->where('land_parcel_id', $request->land_parcel_id);
        }
        if ($request->filled('from')) {
            $query->where('activity_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('activity_date', '<=', $request->to);
        }

        $activities = $query->orderBy('activity_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($activities);
    }

    public function timeline(Request $request)
    {
        return $this->index($request);
    }

    public function store(StoreCropActivityRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        CropActivityType::where('id', $request->activity_type_id)->where('tenant_id', $tenantId)->firstOrFail();
        CropCycle::where('id', $request->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
        $project = Project::where('id', $request->project_id)->where('tenant_id', $tenantId)->firstOrFail();
        if ($project->crop_cycle_id !== $request->crop_cycle_id) {
            abort(422, 'Project must belong to the selected crop cycle.');
        }
        if ($request->land_parcel_id) {
            LandParcel::where('id', $request->land_parcel_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        $inputs = $request->input('inputs', []);
        $labour = $request->input('labour', []);

        foreach ($inputs as $l) {
            InvStore::where('id', $l['store_id'])->where('tenant_id', $tenantId)->firstOrFail();
            InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }
        foreach ($labour as $l) {
            LabWorker::where('id', $l['worker_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $activity = CropActivity::create([
            'tenant_id' => $tenantId,
            'doc_no' => $request->doc_no,
            'activity_type_id' => $request->activity_type_id,
            'activity_date' => $request->activity_date,
            'crop_cycle_id' => $request->crop_cycle_id,
            'project_id' => $request->project_id,
            'land_parcel_id' => $request->land_parcel_id,
            'notes' => $request->notes,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
        ]);

        foreach ($inputs as $l) {
            CropActivityInput::create([
                'tenant_id' => $tenantId,
                'activity_id' => $activity->id,
                'store_id' => $l['store_id'],
                'item_id' => $l['item_id'],
                'qty' => $l['qty'],
            ]);
        }

        foreach ($labour as $l) {
            CropActivityLabour::create([
                'tenant_id' => $tenantId,
                'activity_id' => $activity->id,
                'worker_id' => $l['worker_id'],
                'rate_basis' => $l['rate_basis'] ?? null,
                'units' => $l['units'],
                'rate' => $l['rate'],
            ]);
        }

        return response()->json($activity->load(['type', 'cropCycle', 'project', 'landParcel', 'inputs.item', 'inputs.store', 'labour.worker']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $activity = CropActivity::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['type', 'cropCycle', 'project', 'landParcel', 'inputs.item', 'inputs.store', 'labour.worker', 'postingGroup'])
            ->firstOrFail();
        return response()->json($activity);
    }

    public function update(UpdateCropActivityRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $activity = CropActivity::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'activity_type_id', 'activity_date', 'crop_cycle_id', 'project_id', 'land_parcel_id', 'notes']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('land_parcel_id') && $request->land_parcel_id === null) {
            $data['land_parcel_id'] = null;
        }
        $activity->update($data);

        if (isset($data['project_id']) || isset($data['crop_cycle_id'])) {
            $project = Project::where('id', $activity->project_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($project->crop_cycle_id !== $activity->crop_cycle_id) {
                abort(422, 'Project must belong to the selected crop cycle.');
            }
        }

        if ($request->has('inputs')) {
            CropActivityInput::where('activity_id', $activity->id)->delete();
            foreach ($request->inputs as $l) {
                InvStore::where('id', $l['store_id'])->where('tenant_id', $tenantId)->firstOrFail();
                InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
                CropActivityInput::create([
                    'tenant_id' => $tenantId,
                    'activity_id' => $activity->id,
                    'store_id' => $l['store_id'],
                    'item_id' => $l['item_id'],
                    'qty' => $l['qty'],
                ]);
            }
        }

        if ($request->has('labour')) {
            CropActivityLabour::where('activity_id', $activity->id)->delete();
            foreach ($request->labour as $l) {
                LabWorker::where('id', $l['worker_id'])->where('tenant_id', $tenantId)->firstOrFail();
                CropActivityLabour::create([
                    'tenant_id' => $tenantId,
                    'activity_id' => $activity->id,
                    'worker_id' => $l['worker_id'],
                    'rate_basis' => $l['rate_basis'] ?? null,
                    'units' => $l['units'],
                    'rate' => $l['rate'],
                ]);
            }
        }

        return response()->json($activity->fresh(['type', 'cropCycle', 'project', 'landParcel', 'inputs.item', 'inputs.store', 'labour.worker']));
    }

    public function post(PostCropActivityRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->postActivity($id, $tenantId, $request->posting_date, $request->idempotency_key);
        return response()->json($pg, 201);
    }

    public function reverse(ReverseCropActivityRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseActivity($id, $tenantId, $request->posting_date, $request->reason ?? '', $request->idempotency_key);
        return response()->json($pg, 201);
    }
}
