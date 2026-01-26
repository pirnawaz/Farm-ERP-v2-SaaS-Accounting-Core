<?php

namespace App\Http\Controllers;

use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvStore;
use App\Models\InvItem;
use App\Models\Party;
use App\Http\Requests\StoreInvGrnRequest;
use App\Http\Requests\UpdateInvGrnRequest;
use App\Http\Requests\PostInvGrnRequest;
use App\Http\Requests\ReverseInvGrnRequest;
use App\Services\TenantContext;
use App\Services\InventoryPostingService;
use Illuminate\Http\Request;

class InvGrnController extends Controller
{
    public function __construct(
        private InventoryPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvGrn::where('tenant_id', $tenantId)->with(['store', 'supplier', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $grns = $query->orderBy('doc_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($grns);
    }

    public function store(StoreInvGrnRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        InvStore::where('id', $request->store_id)->where('tenant_id', $tenantId)->firstOrFail();
        if ($request->supplier_party_id) {
            Party::where('id', $request->supplier_party_id)->where('tenant_id', $tenantId)->firstOrFail();
        }
        foreach ($request->lines as $l) {
            InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $grn = InvGrn::create([
            'tenant_id' => $tenantId,
            'doc_no' => $request->doc_no,
            'supplier_party_id' => $request->supplier_party_id,
            'store_id' => $request->store_id,
            'doc_date' => $request->doc_date,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
        ]);

        foreach ($request->lines as $l) {
            $lineTotal = (string) ((float) $l['qty'] * (float) $l['unit_cost']);
            InvGrnLine::create([
                'tenant_id' => $tenantId,
                'grn_id' => $grn->id,
                'item_id' => $l['item_id'],
                'qty' => $l['qty'],
                'unit_cost' => $l['unit_cost'],
                'line_total' => $lineTotal,
            ]);
        }

        return response()->json($grn->load(['store', 'supplier', 'lines.item']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $grn = InvGrn::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['store', 'supplier', 'lines.item', 'postingGroup'])
            ->firstOrFail();
        return response()->json($grn);
    }

    public function update(UpdateInvGrnRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $grn = InvGrn::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'supplier_party_id', 'store_id', 'doc_date']);
        $data = array_filter($data, function ($v) { return $v !== null; });
        if ($request->has('supplier_party_id') && $request->supplier_party_id === null) {
            $data['supplier_party_id'] = null;
        }
        $grn->update($data);

        if ($request->has('lines')) {
            InvGrnLine::where('grn_id', $grn->id)->delete();
            foreach ($request->lines as $l) {
                InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
                $lineTotal = (string) ((float) $l['qty'] * (float) $l['unit_cost']);
                InvGrnLine::create([
                    'tenant_id' => $tenantId,
                    'grn_id' => $grn->id,
                    'item_id' => $l['item_id'],
                    'qty' => $l['qty'],
                    'unit_cost' => $l['unit_cost'],
                    'line_total' => $lineTotal,
                ]);
            }
        }

        return response()->json($grn->fresh(['store', 'supplier', 'lines.item']));
    }

    public function post(PostInvGrnRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->postGRN($id, $tenantId, $request->posting_date, $request->idempotency_key);
        
        // Log audit event
        $this->logAudit($request, 'InvGrn', $id, 'POST', [
            'posting_date' => $request->posting_date,
            'posting_group_id' => $pg->id,
        ]);
        
        return response()->json($pg, 201);
    }

    public function reverse(ReverseInvGrnRequest $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseGRN($id, $tenantId, $request->posting_date, $request->reason);
        
        // Log audit event
        $this->logAudit($request, 'InvGrn', $id, 'REVERSE', [
            'posting_date' => $request->posting_date,
            'reason' => $request->reason,
            'posting_group_id' => $pg->id,
        ]);
        
        return response()->json($pg, 201);
    }
}
