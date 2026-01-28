<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostMachineWorkLogRequest;
use App\Http\Requests\ReverseMachineWorkLogRequest;
use App\Models\MachineWorkLog;
use App\Models\MachineWorkLogCostLine;
use App\Models\Machine;
use App\Models\Party;
use App\Models\Project;
use App\Services\Machinery\MachineryPostingService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MachineWorkLogController extends Controller
{
    public function __construct(
        private MachineryPostingService $postingService
    ) {}
    private const PREFIX = 'MWL-';
    private const PAD_LENGTH = 6;

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = MachineWorkLog::where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'cropCycle', 'postingGroup', 'lines.party']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('machine_id')) {
            $query->where('machine_id', $request->machine_id);
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

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        // If lines are sent, return validation error
        if ($request->has('lines')) {
            abort(422, 'cost lines deprecated; use charges');
        }

        $validated = $request->validate([
            'machine_id' => ['required', 'uuid', 'exists:machines,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'work_date' => ['nullable', 'date'],
            'meter_start' => ['nullable', 'numeric', 'min:0'],
            'meter_end' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'pool_scope' => ['nullable', 'string', Rule::in([MachineWorkLog::POOL_SCOPE_SHARED, MachineWorkLog::POOL_SCOPE_HARI_ONLY])],
        ]);

        if (isset($validated['meter_start']) && isset($validated['meter_end']) && $validated['meter_end'] < $validated['meter_start']) {
            abort(422, 'meter_end must be greater than or equal to meter_start');
        }

        Machine::where('id', $validated['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();
        $project = Project::where('id', $validated['project_id'])->where('tenant_id', $tenantId)->firstOrFail();
        $cropCycleId = $project->crop_cycle_id;

        $usageQty = 0;
        if (isset($validated['meter_start']) && isset($validated['meter_end'])) {
            $usageQty = (float) $validated['meter_end'] - (float) $validated['meter_start'];
        }

        $workLogNo = $this->generateWorkLogNo($tenantId);

        $log = MachineWorkLog::create([
            'tenant_id' => $tenantId,
            'work_log_no' => $workLogNo,
            'machine_id' => $validated['machine_id'],
            'project_id' => $validated['project_id'],
            'crop_cycle_id' => $cropCycleId,
            'pool_scope' => $validated['pool_scope'] ?? MachineWorkLog::POOL_SCOPE_SHARED,
            'work_date' => $validated['work_date'] ?? null,
            'meter_start' => $validated['meter_start'] ?? null,
            'meter_end' => $validated['meter_end'] ?? null,
            'usage_qty' => $usageQty,
            'notes' => $validated['notes'] ?? null,
            'status' => MachineWorkLog::STATUS_DRAFT,
        ]);

        return response()->json($log->load(['machine', 'project', 'cropCycle', 'lines.party']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $log = MachineWorkLog::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'cropCycle', 'postingGroup', 'reversalPostingGroup', 'lines.party'])
            ->firstOrFail();
        return response()->json($log);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $log = MachineWorkLog::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineWorkLog::STATUS_DRAFT)
            ->firstOrFail();

        // If lines are sent, return validation error
        if ($request->has('lines')) {
            abort(422, 'cost lines deprecated; use charges');
        }

        $rules = [
            'machine_id' => ['sometimes', 'uuid', 'exists:machines,id'],
            'project_id' => ['sometimes', 'uuid', 'exists:projects,id'],
            'work_date' => ['nullable', 'date'],
            'meter_start' => ['nullable', 'numeric', 'min:0'],
            'meter_end' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'pool_scope' => ['nullable', 'string', Rule::in([MachineWorkLog::POOL_SCOPE_SHARED, MachineWorkLog::POOL_SCOPE_HARI_ONLY])],
        ];
        $validated = $request->validate($rules);

        if (isset($validated['meter_start']) && isset($validated['meter_end']) && $validated['meter_end'] < $validated['meter_start']) {
            abort(422, 'meter_end must be greater than or equal to meter_start');
        }

        $projectId = $validated['project_id'] ?? $log->project_id;
        $project = Project::where('id', $projectId)->where('tenant_id', $tenantId)->firstOrFail();
        $cropCycleId = $project->crop_cycle_id;

        $usageQty = $log->usage_qty;
        if (array_key_exists('meter_start', $validated) || array_key_exists('meter_end', $validated)) {
            $meterStart = $validated['meter_start'] ?? $log->meter_start;
            $meterEnd = $validated['meter_end'] ?? $log->meter_end;
            if ($meterStart !== null && $meterEnd !== null) {
                $usageQty = (float) $meterEnd - (float) $meterStart;
            } else {
                $usageQty = 0;
            }
        }

        $header = [
            'crop_cycle_id' => $cropCycleId,
            'usage_qty' => $usageQty,
        ];
        foreach (['machine_id', 'project_id', 'work_date', 'meter_start', 'meter_end', 'notes', 'pool_scope'] as $key) {
            if (array_key_exists($key, $validated)) {
                $header[$key] = $validated[$key];
            }
        }

        if (isset($validated['machine_id'])) {
            Machine::where('id', $validated['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $log->update($header);

        return response()->json($log->fresh(['machine', 'project', 'cropCycle', 'lines.party']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $log = MachineWorkLog::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', MachineWorkLog::STATUS_DRAFT)
            ->firstOrFail();

        $log->delete();
        return response()->json(null, 204);
    }

    private function generateWorkLogNo(string $tenantId): string
    {
        $last = MachineWorkLog::where('tenant_id', $tenantId)
            ->where('work_log_no', 'like', self::PREFIX . '%')
            ->orderByRaw('LENGTH(work_log_no) DESC, work_log_no DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)$/', $last->work_log_no, $m)) {
            $next = (int) $m[1] + 1;
        }

        return self::PREFIX . str_pad((string) $next, self::PAD_LENGTH, '0', STR_PAD_LEFT);
    }

    public function post(PostMachineWorkLogRequest $request, string $id)
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $pg = $this->postingService->postWorkLog(
            $id,
            $tenantId,
            $request->posting_date,
            $idempotencyKey
        );

        $workLog = MachineWorkLog::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'cropCycle', 'postingGroup', 'lines.party'])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'work_log' => $workLog,
        ], 201);
    }

    public function reverse(ReverseMachineWorkLogRequest $request, string $id)
    {
        $this->authorizeReversal($request);

        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseWorkLog(
            $id,
            $tenantId,
            $request->posting_date,
            $request->input('reason')
        );

        $workLog = MachineWorkLog::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['machine', 'project', 'cropCycle', 'postingGroup', 'reversalPostingGroup', 'lines.party'])
            ->firstOrFail();

        return response()->json([
            'posting_group' => $pg,
            'work_log' => $workLog,
        ], 201);
    }
}
