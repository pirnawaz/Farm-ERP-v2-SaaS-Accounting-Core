<?php

namespace App\Http\Controllers;

use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\InvStore;
use App\Models\InvItem;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Machine;
use App\Http\Requests\StoreInvIssueRequest;
use App\Http\Requests\UpdateInvIssueRequest;
use App\Http\Requests\PostInvIssueRequest;
use App\Http\Requests\ReverseInvIssueRequest;
use App\Services\TenantContext;
use App\Services\InventoryPostingService;
use Illuminate\Http\Request;

class InvIssueController extends Controller
{
    public function __construct(
        private InventoryPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvIssue::where('tenant_id', $tenantId)->with(['store', 'cropCycle', 'project', 'machine', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $issues = $query->orderBy('doc_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($issues);
    }

    public function store(StoreInvIssueRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        InvStore::where('id', $request->store_id)->where('tenant_id', $tenantId)->firstOrFail();
        CropCycle::where('id', $request->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
        Project::where('id', $request->project_id)->where('tenant_id', $tenantId)->firstOrFail();
        if ($request->filled('machine_id')) {
            Machine::where('id', $request->machine_id)->where('tenant_id', $tenantId)->firstOrFail();
        }
        foreach ($request->lines as $l) {
            InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $docNo = $request->filled('doc_no') ? trim($request->doc_no) : null;
        if ($docNo === '') {
            $docNo = null;
        }
        if ($docNo === null) {
            $docNo = $this->generateIssueDocNo($tenantId);
        }

        $issue = InvIssue::create([
            'tenant_id' => $tenantId,
            'doc_no' => $docNo,
            'store_id' => $request->store_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'project_id' => $request->project_id,
            'activity_id' => $request->activity_id,
            'machine_id' => $request->machine_id,
            'doc_date' => $request->doc_date,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
            'allocation_mode' => $request->allocation_mode,
            'hari_id' => $request->filled('hari_id') ? $request->hari_id : null,
            'sharing_rule_id' => $request->filled('sharing_rule_id') ? $request->sharing_rule_id : null,
            'landlord_share_pct' => $request->filled('landlord_share_pct') ? $request->landlord_share_pct : null,
            'hari_share_pct' => $request->filled('hari_share_pct') ? $request->hari_share_pct : null,
        ]);

        foreach ($request->lines as $l) {
            InvIssueLine::create([
                'tenant_id' => $tenantId,
                'issue_id' => $issue->id,
                'item_id' => $l['item_id'],
                'qty' => $l['qty'],
            ]);
        }

        return response()->json($issue->load(['store', 'cropCycle', 'project', 'machine', 'lines.item', 'hari', 'sharingRule']), 201);
    }

    private function generateIssueDocNo(string $tenantId): string
    {
        $last = InvIssue::where('tenant_id', $tenantId)
            ->where('doc_no', 'like', 'ISS-%')
            ->orderByRaw('LENGTH(doc_no) DESC, doc_no DESC')
            ->first();
        $next = 1;
        if ($last && preg_match('/^ISS-(\d+)$/', $last->doc_no, $m)) {
            $next = (int) $m[1] + 1;
        }
        return 'ISS-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $issue = InvIssue::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['store', 'cropCycle', 'project', 'machine', 'lines.item', 'postingGroup', 'hari', 'sharingRule'])
            ->firstOrFail();
        return response()->json($issue);
    }

    public function update(UpdateInvIssueRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $issue = InvIssue::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'store_id', 'crop_cycle_id', 'project_id', 'activity_id', 'machine_id', 'doc_date', 'allocation_mode', 'hari_id', 'sharing_rule_id', 'landlord_share_pct', 'hari_share_pct']);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
        if (isset($data['doc_no']) && $data['doc_no'] === '') {
            unset($data['doc_no']);
        }
        if ($request->has('activity_id') && $request->activity_id === null) {
            $data['activity_id'] = null;
        }
        if ($request->has('machine_id') && $request->machine_id === null) {
            $data['machine_id'] = null;
        }
        if ($request->filled('machine_id')) {
            Machine::where('id', $request->machine_id)->where('tenant_id', $tenantId)->firstOrFail();
        }
        if ($request->has('allocation_mode')) {
            $data['allocation_mode'] = $request->allocation_mode;
            $data['hari_id'] = $request->filled('hari_id') ? $request->hari_id : null;
            $data['sharing_rule_id'] = $request->filled('sharing_rule_id') ? $request->sharing_rule_id : null;
            $data['landlord_share_pct'] = $request->filled('landlord_share_pct') ? $request->landlord_share_pct : null;
            $data['hari_share_pct'] = $request->filled('hari_share_pct') ? $request->hari_share_pct : null;
        }
        $issue->update($data);

        if ($request->has('lines')) {
            CropCycle::where('id', $issue->crop_cycle_id)->where('tenant_id', $tenantId)->firstOrFail();
            Project::where('id', $issue->project_id)->where('tenant_id', $tenantId)->firstOrFail();
            InvIssueLine::where('issue_id', $issue->id)->delete();
            foreach ($request->lines as $l) {
                InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
                InvIssueLine::create([
                    'tenant_id' => $tenantId,
                    'issue_id' => $issue->id,
                    'item_id' => $l['item_id'],
                    'qty' => $l['qty'],
                ]);
            }
        }

        return response()->json($issue->fresh(['store', 'cropCycle', 'project', 'machine', 'lines.item', 'hari', 'sharingRule']));
    }

    public function post(PostInvIssueRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->postIssue($id, $tenantId, $request->posting_date, $request->idempotency_key);
        return response()->json($pg, 201);
    }

    public function reverse(ReverseInvIssueRequest $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseIssue($id, $tenantId, $request->posting_date, $request->reason);
        return response()->json($pg, 201);
    }
}
