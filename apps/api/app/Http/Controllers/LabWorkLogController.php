<?php

namespace App\Http\Controllers;

use App\Models\LabWorkLog;
use App\Models\LabWorker;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Machine;
use App\Http\Requests\StoreLabWorkLogRequest;
use App\Http\Requests\UpdateLabWorkLogRequest;
use App\Http\Requests\PostLabWorkLogRequest;
use App\Http\Requests\ReverseLabWorkLogRequest;
use App\Services\TenantContext;
use App\Services\LabourPostingService;
use Illuminate\Http\Request;

class LabWorkLogController extends Controller
{
    public function __construct(
        private LabourPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = LabWorkLog::where('tenant_id', $tenantId)->with(['worker', 'cropCycle', 'project', 'machine', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('worker_id')) {
            $query->where('worker_id', $request->worker_id);
        }
        if ($request->filled('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('from')) {
            $query->where('work_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('work_date', '<=', $request->to);
        }

        $logs = $query->orderBy('work_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($logs);
    }

    public function store(StoreLabWorkLogRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        LabWorker::where('id', $request->worker_id)->where('tenant_id', $tenantId)->firstOrFail();
        CropCycle::where('id', $request->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
        $project = Project::where('id', $request->project_id)->where('tenant_id', $tenantId)->firstOrFail();
        if ($project->crop_cycle_id !== $request->crop_cycle_id) {
            abort(422, 'Project does not belong to the selected crop cycle.');
        }
        if ($request->filled('machine_id')) {
            Machine::where('id', $request->machine_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        $amount = (float) $request->units * (float) $request->rate;

        $docNo = $request->filled('doc_no') ? trim($request->doc_no) : null;
        if ($docNo === '') {
            $docNo = null;
        }
        if ($docNo === null) {
            $docNo = $this->generateDocNo($tenantId);
        }

        $log = LabWorkLog::create([
            'tenant_id' => $tenantId,
            'doc_no' => $docNo,
            'worker_id' => $request->worker_id,
            'work_date' => $request->work_date,
            'crop_cycle_id' => $request->crop_cycle_id,
            'project_id' => $request->project_id,
            'activity_id' => $request->activity_id,
            'machine_id' => $request->machine_id,
            'rate_basis' => $request->rate_basis,
            'units' => $request->units,
            'rate' => $request->rate,
            'amount' => round($amount, 2),
            'notes' => $request->notes,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
        ]);

        return response()->json($log->load(['worker', 'cropCycle', 'project', 'machine']), 201);
    }

    private function generateDocNo(string $tenantId): string
    {
        $last = LabWorkLog::where('tenant_id', $tenantId)
            ->where('doc_no', 'like', 'WL-%')
            ->orderByRaw('LENGTH(doc_no) DESC, doc_no DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^WL-(\d+)$/', $last->doc_no, $m)) {
            $next = (int) $m[1] + 1;
        }

        return 'WL-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $log = LabWorkLog::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['worker', 'cropCycle', 'project', 'machine', 'postingGroup'])
            ->firstOrFail();
        return response()->json($log);
    }

    public function update(UpdateLabWorkLogRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $log = LabWorkLog::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'worker_id', 'work_date', 'crop_cycle_id', 'project_id', 'activity_id', 'machine_id', 'rate_basis', 'units', 'rate', 'notes']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('activity_id') && $request->activity_id === null) {
            $data['activity_id'] = null;
        }
        if ($request->has('machine_id') && $request->machine_id === null) {
            $data['machine_id'] = null;
        }
        if ($request->filled('machine_id')) {
            Machine::where('id', $request->machine_id)->where('tenant_id', $tenantId)->firstOrFail();
        }

        if (isset($data['units']) || isset($data['rate'])) {
            $units = (float) ($data['units'] ?? $log->units);
            $rate = (float) ($data['rate'] ?? $log->rate);
            $data['amount'] = round($units * $rate, 2);
        }

        $log->update($data);

        if (isset($data['crop_cycle_id']) || isset($data['project_id'])) {
            $project = Project::where('id', $log->project_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($project->crop_cycle_id !== $log->crop_cycle_id) {
                abort(422, 'Project does not belong to the selected crop cycle.');
            }
        }

        return response()->json($log->fresh(['worker', 'cropCycle', 'project', 'machine']));
    }

    public function post(PostLabWorkLogRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->idempotency_key;
        $pg = $this->postingService->postWorkLog($id, $tenantId, $request->posting_date, $idempotencyKey);
        return response()->json($pg, 201);
    }

    public function reverse(ReverseLabWorkLogRequest $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseWorkLog($id, $tenantId, $request->posting_date, $request->reason);
        return response()->json($pg, 201);
    }
}
