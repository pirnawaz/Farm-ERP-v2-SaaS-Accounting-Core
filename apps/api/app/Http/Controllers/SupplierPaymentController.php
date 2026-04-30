<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\AccountsPayable\SupplierPaymentPostingService;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillPaymentAllocation;
use App\Models\SupplierPayment;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentController extends Controller
{
    public function __construct(
        private SupplierPaymentPostingService $postingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = TenantScoped::for(SupplierPayment::query(), $tenantId)->with(['supplier:id,name']);

        if ($request->filled('supplier_id')) {
            $q->where('supplier_id', $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json($q->orderByDesc('payment_date')->orderByDesc('created_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', 'string', 'in:CASH,BANK'],
            'bank_account_id' => ['nullable', 'uuid'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'uuid'],
            'allocations.*.amount_applied' => ['required', 'numeric', 'min:0.01'],
        ]);

        TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($validated['supplier_id']);

        $sum = array_sum(array_map(fn ($a) => (float) $a['amount_applied'], $validated['allocations']));
        if (abs($sum - (float) $validated['total_amount']) > 0.02) {
            throw ValidationException::withMessages([
                'total_amount' => ['Sum of allocations must equal total_amount.'],
            ]);
        }

        $payment = DB::transaction(function () use ($tenantId, $validated, $request) {
            $payment = SupplierPayment::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $validated['supplier_id'],
                'payment_date' => $validated['payment_date'],
                'payment_method' => $validated['payment_method'],
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'status' => SupplierPayment::STATUS_DRAFT,
                'total_amount' => $validated['total_amount'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->header('X-User-Id'),
            ]);

            foreach ($validated['allocations'] as $a) {
                $bill = TenantScoped::for(SupplierBill::query(), $tenantId)->findOrFail($a['supplier_bill_id']);
                if ((string) $bill->supplier_id !== (string) $payment->supplier_id) {
                    throw ValidationException::withMessages(['allocations' => ['Allocated bill supplier must match payment supplier.']]);
                }
                if (! in_array($bill->status, [SupplierBill::STATUS_POSTED, SupplierBill::STATUS_PARTIALLY_PAID], true)) {
                    throw ValidationException::withMessages(['allocations' => ['Bills must be POSTED or PARTIALLY_PAID to allocate.']]);
                }

                SupplierBillPaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'supplier_payment_id' => $payment->id,
                    'supplier_bill_id' => $bill->id,
                    'amount_applied' => $a['amount_applied'],
                ]);
            }

            return $payment;
        });

        return response()->json($payment->fresh(['supplier:id,name', 'allocations.supplierBill']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $payment = TenantScoped::for(SupplierPayment::query(), $tenantId)
            ->with(['supplier:id,name', 'allocations', 'postingGroup:id,posting_date'])
            ->findOrFail($id);

        return response()->json($payment);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $payment = TenantScoped::for(SupplierPayment::query(), $tenantId)->with(['allocations'])->findOrFail($id);
        if ($payment->status !== SupplierPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Only DRAFT supplier payments can be edited.']]);
        }

        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'uuid'],
            'payment_date' => ['sometimes', 'date'],
            'payment_method' => ['sometimes', 'string', 'in:CASH,BANK'],
            'bank_account_id' => ['nullable', 'uuid'],
            'total_amount' => ['sometimes', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_bill_id' => ['required', 'uuid'],
            'allocations.*.amount_applied' => ['required', 'numeric', 'min:0.01'],
        ]);

        $nextSupplierId = $validated['supplier_id'] ?? $payment->supplier_id;
        TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($nextSupplierId);

        $nextTotal = (float) ($validated['total_amount'] ?? $payment->total_amount);
        $sum = array_sum(array_map(fn ($a) => (float) $a['amount_applied'], $validated['allocations']));
        if (abs($sum - $nextTotal) > 0.02) {
            throw ValidationException::withMessages(['total_amount' => ['Sum of allocations must equal total_amount.']]);
        }

        $payment = DB::transaction(function () use ($payment, $validated, $tenantId, $nextSupplierId) {
            $payment->update([
                'supplier_id' => $nextSupplierId,
                'payment_date' => $validated['payment_date'] ?? $payment->payment_date,
                'payment_method' => $validated['payment_method'] ?? $payment->payment_method,
                'bank_account_id' => array_key_exists('bank_account_id', $validated) ? ($validated['bank_account_id'] ?? null) : $payment->bank_account_id,
                'total_amount' => $validated['total_amount'] ?? $payment->total_amount,
                'notes' => array_key_exists('notes', $validated) ? ($validated['notes'] ?? null) : $payment->notes,
            ]);

            SupplierBillPaymentAllocation::where('tenant_id', $tenantId)->where('supplier_payment_id', $payment->id)->delete();
            foreach ($validated['allocations'] as $a) {
                $bill = TenantScoped::for(SupplierBill::query(), $tenantId)->findOrFail($a['supplier_bill_id']);
                if ((string) $bill->supplier_id !== (string) $payment->supplier_id) {
                    throw ValidationException::withMessages(['allocations' => ['Allocated bill supplier must match payment supplier.']]);
                }
                if (! in_array($bill->status, [SupplierBill::STATUS_POSTED, SupplierBill::STATUS_PARTIALLY_PAID], true)) {
                    throw ValidationException::withMessages(['allocations' => ['Bills must be POSTED or PARTIALLY_PAID to allocate.']]);
                }

                SupplierBillPaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'supplier_payment_id' => $payment->id,
                    'supplier_bill_id' => $bill->id,
                    'amount_applied' => $a['amount_applied'],
                ]);
            }

            return $payment;
        });

        return response()->json($payment->fresh(['supplier:id,name', 'allocations']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $payment = TenantScoped::for(SupplierPayment::query(), $tenantId)->findOrFail($id);
        if ($payment->status !== SupplierPayment::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Only DRAFT supplier payments can be deleted.']]);
        }
        $payment->delete();
        return response()->json(null, 204);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $pg = $this->postingService->post(
            $id,
            $tenantId,
            $validated['posting_date'],
            $validated['idempotency_key'] ?? null,
            $request->header('X-User-Id')
        );

        return response()->json($pg, 201);
    }
}

