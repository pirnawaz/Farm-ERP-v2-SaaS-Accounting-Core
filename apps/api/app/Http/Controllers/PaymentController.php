<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Party;
use App\Models\Settlement;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Requests\PostPaymentRequest;
use App\Services\TenantContext;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService
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

        $arService = app(\App\Services\SaleARService::class);
        $preview = $arService->getAllocationPreview($partyId, $tenantId, $amount, $postingDate);

        return response()->json($preview);
    }

    public function post(PostPaymentRequest $request, string $id)
    {
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

        return response()->json($postingGroup, 201);
    }
}
