<?php

namespace App\Http\Controllers;

use App\Models\InvTransfer;
use App\Models\InvTransferLine;
use App\Models\InvStore;
use App\Models\InvItem;
use App\Http\Requests\StoreInvTransferRequest;
use App\Http\Requests\UpdateInvTransferRequest;
use App\Http\Requests\PostInvTransferRequest;
use App\Http\Requests\ReverseInvTransferRequest;
use App\Services\TenantContext;
use App\Services\InventoryPostingService;
use Illuminate\Http\Request;

class InvTransferController extends Controller
{
    public function __construct(
        private InventoryPostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = InvTransfer::where('tenant_id', $tenantId)->with(['fromStore', 'toStore', 'postingGroup']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_store_id')) {
            $query->where('from_store_id', $request->from_store_id);
        }
        if ($request->filled('to_store_id')) {
            $query->where('to_store_id', $request->to_store_id);
        }
        if ($request->filled('from')) {
            $query->where('doc_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('doc_date', '<=', $request->to);
        }

        $transfers = $query->orderBy('doc_date', 'desc')->orderBy('created_at', 'desc')->get();
        return response()->json($transfers);
    }

    public function store(StoreInvTransferRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        InvStore::where('id', $request->from_store_id)->where('tenant_id', $tenantId)->firstOrFail();
        InvStore::where('id', $request->to_store_id)->where('tenant_id', $tenantId)->firstOrFail();
        foreach ($request->lines as $l) {
            InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $docNo = $request->filled('doc_no') ? trim($request->doc_no) : null;
        if ($docNo === '') {
            $docNo = null;
        }
        if ($docNo === null) {
            $docNo = $this->generateTransferDocNo($tenantId);
        }

        $transfer = InvTransfer::create([
            'tenant_id' => $tenantId,
            'doc_no' => $docNo,
            'from_store_id' => $request->from_store_id,
            'to_store_id' => $request->to_store_id,
            'doc_date' => $request->doc_date,
            'status' => 'DRAFT',
            'created_by' => $request->header('X-User-Id'),
        ]);

        foreach ($request->lines as $l) {
            InvTransferLine::create([
                'tenant_id' => $tenantId,
                'transfer_id' => $transfer->id,
                'item_id' => $l['item_id'],
                'qty' => $l['qty'],
            ]);
        }

        return response()->json($transfer->load(['fromStore', 'toStore', 'lines.item']), 201);
    }

    private function generateTransferDocNo(string $tenantId): string
    {
        $last = InvTransfer::where('tenant_id', $tenantId)
            ->where('doc_no', 'like', 'TRF-%')
            ->orderByRaw('LENGTH(doc_no) DESC, doc_no DESC')
            ->first();
        $next = 1;
        if ($last && preg_match('/^TRF-(\d+)$/', $last->doc_no, $m)) {
            $next = (int) $m[1] + 1;
        }
        return 'TRF-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $transfer = InvTransfer::where('id', $id)->where('tenant_id', $tenantId)
            ->with(['fromStore', 'toStore', 'lines.item', 'postingGroup'])
            ->firstOrFail();
        return response()->json($transfer);
    }

    public function update(UpdateInvTransferRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $transfer = InvTransfer::where('id', $id)->where('tenant_id', $tenantId)->where('status', 'DRAFT')->firstOrFail();

        $data = $request->only(['doc_no', 'from_store_id', 'to_store_id', 'doc_date']);
        $data = array_filter($data, fn ($v) => $v !== null);
        if (isset($data['doc_no']) && $data['doc_no'] === '') {
            unset($data['doc_no']);
        }
        $transfer->update($data);

        if ($request->has('lines')) {
            InvStore::where('id', $transfer->from_store_id)->where('tenant_id', $tenantId)->firstOrFail();
            InvStore::where('id', $transfer->to_store_id)->where('tenant_id', $tenantId)->firstOrFail();
            InvTransferLine::where('transfer_id', $transfer->id)->delete();
            foreach ($request->lines as $l) {
                InvItem::where('id', $l['item_id'])->where('tenant_id', $tenantId)->firstOrFail();
                InvTransferLine::create([
                    'tenant_id' => $tenantId,
                    'transfer_id' => $transfer->id,
                    'item_id' => $l['item_id'],
                    'qty' => $l['qty'],
                ]);
            }
        }

        return response()->json($transfer->fresh(['fromStore', 'toStore', 'lines.item']));
    }

    public function post(PostInvTransferRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $idempotencyKey = $request->filled('idempotency_key')
            ? $request->idempotency_key
            : 'inv_transfer:' . $id . ':post';
        $pg = $this->postingService->postTransfer($id, $tenantId, $request->posting_date, $idempotencyKey);
        return response()->json($pg, 201);
    }

    public function reverse(ReverseInvTransferRequest $request, string $id)
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $pg = $this->postingService->reverseTransfer($id, $tenantId, $request->posting_date, $request->reason);
        return response()->json($pg, 201);
    }
}
