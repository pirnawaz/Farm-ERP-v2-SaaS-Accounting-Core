<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Party;
use App\Models\Settlement;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\PostPaymentRequest;
use App\Http\Requests\ReversePaymentRequest;
use App\Services\TenantContext;
use App\Services\PaymentService;
use App\Services\SaleARService;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private SaleARService $saleARService,
        private BillPaymentService $billPaymentService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Payment::where('tenant_id', $tenantId)
            ->with(['party', 'settlement']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->has('party_id')) {
            $query->where('party_id', $request->party_id);
        }

        if ($request->has('date_from')) {
            $query->where('payment_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('payment_date', '<=', $request->date_to);
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($payments);
    }

    public function store(StorePaymentRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        // Verify party belongs to tenant
        Party::where('id', $request->party_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verify settlement belongs to tenant if provided
        if ($request->settlement_id) {
            Settlement::where('id', $request->settlement_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $payment = Payment::create([
            'tenant_id' => $tenantId,
            'party_id' => $request->party_id,
            'direction' => $request->direction,
            'amount' => $request->amount,
            'payment_date' => $request->payment_date,
            'method' => $request->method,
            'reference' => $request->reference,
            'settlement_id' => $request->settlement_id,
            'notes' => $request->notes,
            'purpose' => $request->get('purpose', 'GENERAL'),
            'status' => 'DRAFT',
        ]);

        return response()->json($payment->load(['party', 'settlement']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $payment = Payment::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['party', 'settlement', 'postingGroup', 'saleAllocations.sale'])
            ->firstOrFail();

        return response()->json($payment);
    }

    public function update(UpdatePaymentRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $payment = Payment::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        // Verify party belongs to tenant if changed
        if ($request->has('party_id')) {
            Party::where('id', $request->party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        // Verify settlement belongs to tenant if changed
        if ($request->has('settlement_id') && $request->settlement_id) {
            Settlement::where('id', $request->settlement_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $payment->update($request->validated());

        return response()->json($payment->load(['party', 'settlement']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $payment = Payment::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        $payment->delete();

        return response()->json(null, 204);
    }

    public function allocationPreview(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $partyId = $request->input('party_id');
        $amount = (float) $request->input('amount');
        $postingDate = $request->input('posting_date');

        if (!$partyId || !$amount || !$postingDate) {
            return response()->json(['error' => 'party_id, amount, and posting_date are required'], 422);
        }

        // Verify party belongs to tenant
        Party::where('id', $partyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $preview = $this->saleARService->getAllocationPreview($partyId, $tenantId, $amount, $postingDate);

        return response()->json($preview);
    }

    /**
     * GET /payments/{id}/apply-sales/preview?mode=FIFO|MANUAL
     * Preview FIFO or manual allocations for a posted Payment IN.
     */
    public function applySalesPreview(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $mode = $request->input('mode', 'FIFO');
        if (!in_array($mode, ['FIFO', 'MANUAL'])) {
            return response()->json(['error' => 'mode must be FIFO or MANUAL'], 422);
        }

        try {
            $preview = $this->saleARService->previewApplyPaymentToSales($tenantId, $id, $mode);
            return response()->json($preview);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible (must be posted, not reversed, direction IN).'], 404);
        }
    }

    /**
     * POST /payments/{id}/apply-sales
     * Apply allocations (FIFO or MANUAL). Creates ACTIVE SalePaymentAllocation rows.
     */
    public function applySales(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $mode = $request->input('mode', 'FIFO');
        if (!in_array($mode, ['FIFO', 'MANUAL'])) {
            return response()->json(['error' => 'mode must be FIFO or MANUAL'], 422);
        }
        $allocationDate = $request->input('allocation_date');
        $allocations = $request->input('allocations');
        if ($mode === 'MANUAL' && (!is_array($allocations) || count($allocations) === 0)) {
            return response()->json(['error' => 'allocations array is required for MANUAL mode'], 422);
        }
        $createdBy = $request->user()?->id;

        try {
            $summary = $this->saleARService->applyPaymentToSales($tenantId, $id, $mode, $allocations, $allocationDate, $createdBy);
            return response()->json($summary);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible.'], 404);
        }
    }

    /**
     * POST /payments/{id}/unapply-sales
     * Unapply (void) allocations. Body: { "sale_id": "..." } optional; if omitted, unapply all.
     */
    public function unapplySales(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $saleId = $request->input('sale_id');
        $voidedBy = $request->user()?->id;

        try {
            $summary = $this->saleARService->unapplyPaymentFromSales($tenantId, $id, $saleId, $voidedBy);
            return response()->json($summary);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible.'], 404);
        }
    }

    /**
     * GET /payments/{id}/apply-bills/preview
     * Preview apply supplier payment (OUT) to bills. Query: mode=FIFO|MANUAL
     */
    public function applyBillsPreview(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $mode = $request->input('mode', 'FIFO');
        if (!in_array($mode, ['FIFO', 'MANUAL'])) {
            return response()->json(['error' => 'mode must be FIFO or MANUAL'], 422);
        }
        try {
            $preview = $this->billPaymentService->previewApplyPaymentToBills($tenantId, $id, $mode);
            return response()->json($preview);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible (must be posted, not reversed, direction OUT).'], 404);
        }
    }

    /**
     * POST /payments/{id}/apply-bills
     * Apply supplier payment (OUT) to bills. Body: mode, allocation_date?, allocations[] (for MANUAL: grn_id, amount)
     */
    public function applyBills(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $mode = $request->input('mode', 'FIFO');
        if (!in_array($mode, ['FIFO', 'MANUAL'])) {
            return response()->json(['error' => 'mode must be FIFO or MANUAL'], 422);
        }
        $allocationDate = $request->input('allocation_date');
        $allocations = $request->input('allocations');
        if ($mode === 'MANUAL' && (!is_array($allocations) || count($allocations) === 0)) {
            return response()->json(['error' => 'allocations array is required for MANUAL mode'], 422);
        }
        $createdBy = $request->user()?->id;

        try {
            $created = $this->billPaymentService->applyPaymentToBills($tenantId, $id, $mode, $allocations, $allocationDate, $createdBy);
            return response()->json(['allocations' => $created, 'summary' => $this->billPaymentService->getPaymentAllocationSummary($tenantId, $id)], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible.'], 404);
        }
    }

    /**
     * POST /payments/{id}/unapply-bills
     * Unapply (void) bill allocations. Body: { "grn_id": "..." } optional; if omitted, unapply all.
     */
    public function unapplyBills(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $grnId = $request->input('grn_id');
        $voidedBy = $request->user()?->id;

        try {
            $summary = $this->billPaymentService->unapplyPaymentFromBills($tenantId, $id, $grnId, $voidedBy);
            return response()->json($summary);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Payment not found or not eligible.'], 404);
        }
    }

    public function post(PostPaymentRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->paymentService->postPayment(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key,
            $request->crop_cycle_id,
            $userRole,
            $request->allocation_mode,
            $request->allocations
        );

        // Log audit event
        $this->logAudit($request, 'Payment', $id, 'POST', [
            'posting_date' => $request->posting_date,
            'posting_group_id' => $postingGroup->id,
        ]);

        return response()->json($postingGroup, 201);
    }

    public function reverse(ReversePaymentRequest $request, string $id)
    {
        $this->authorizePosting($request);
        $tenantId = TenantContext::getTenantId($request);
        $reversedBy = $request->user()?->id;

        try {
            $reversalPostingGroup = $this->paymentService->reversePayment(
                $id,
                $tenantId,
                $request->posting_date,
                $request->reason,
                $reversedBy
            );
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            $status = (str_contains($msg, 'Unapply sales allocations') || str_contains($msg, 'Unapply bills')) ? 409 : 422;
            return response()->json(['message' => $msg], $status);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->logAudit($request, 'Payment', $id, 'REVERSE', [
            'posting_date' => $request->posting_date,
            'reversal_posting_group_id' => $reversalPostingGroup->id,
        ]);

        return response()->json([
            'reversal_posting_group' => $reversalPostingGroup,
            'reversal_posting_group_id' => $reversalPostingGroup->id,
        ], 201);
    }
}
