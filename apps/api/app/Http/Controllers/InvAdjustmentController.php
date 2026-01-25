<?php

namespace App\Http\Controllers;

use App\Models\InvAdjustment;
use App\Models\InvAdjustmentLine;
use App\Models\InvStore;
use App\Models\InvItem;
use App\Http\Requests\StoreInvAdjustmentRequest;
use App\Http\Requests\UpdateInvAdjustmentRequest;
use App\Http\Requests\PostInvAdjustmentRequest;
use App\Http\Requests\ReverseInvAdjustmentRequest;
use App\Services\TenantContext;
use App\Services\InventoryPostingService;
use Illuminate\Http\Request;

class InvAdjustmentController extends Controller
{
    public function __construct(
        private InventoryPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvAdjustment::where('tenant_id', $tenantId)->with(['store', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->filled('reason')) {
            $query->where('reason', $request->reason);
        }
        if ($request->filled('from')) {
            $query->where('doc_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('doc_date', '<=', $request->to);
        }

        $adjustments = $query->orderBy('doc_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($adjustments);
    }

    public function store(StoreInvAdjustmentRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        InvStore::where('id', $request->store_id)->where('tenant_id', $tenantId)->firstOrFail();
        foreach ($request->lines as $l) {
            InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $adjustment = InvAdjustment::create([
            'tenant_id' => $tenantId,
            'doc_no' => $request->doc_no,
            'store_id' => $request->store_id,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'doc_date' => $request->doc_date,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
        ]);

        foreach ($request->lines as $l) {
            InvAdjustmentLine::create([
                'tenant_id' => $tenantId,
                'adjustment_id' => $adjustment->id,
                'item_id' => $l['item_id'],
                'qty_delta' => $l['qty_delta'],
            ]);
        }

        return response()->json($adjustment->load(['store', 'lines.item']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $adjustment = InvAdjustment::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['store', 'lines.item', 'postingGroup'])
            ->firstOrFail();
        return response()->json($adjustment);
    }

    public function update(UpdateInvAdjustmentRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $adjustment = InvAdjustment::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'store_id', 'reason', 'doc_date']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if ($request->has('notes')) {
            $data['notes'] = $request->notes;
        }
        $adjustment->update($data);

        if ($request->has('lines')) {
            InvStore::where('id', $adjustment->store_id)->where('tenant_id', $tenantId)->firstOrFail();
            InvAdjustmentLine::where('adjustment_id', $adjustment->id)->delete();
            foreach ($request->lines as $l) {
                InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
                InvAdjustmentLine::create([
                    'tenant_id' => $tenantId,
                    'adjustment_id' => $adjustment->id,
                    'item_id' => $l['item_id'],
                    'qty_delta' => $l['qty_delta'],
                ]);
            }
        }

        return response()->json($adjustment->fresh(['store', 'lines.item']));
    }

    public function post(PostInvAdjustmentRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->filled('idempotency_key')
            ? $request->idempotency_key
            : 'inv_adjustment:' . $id . ':post';
        $pg = $this->postingService->postAdjustment($id, $tenantId, $request->posting_date, $idempotencyKey);
        return response()->json($pg, 201);
    }

    public function reverse(ReverseInvAdjustmentRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseAdjustment($id, $tenantId, $request->posting_date, $request->reason);
        return response()->json($pg, 201);
    }
}
