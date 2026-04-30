<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\AccountsPayable\SupplierBillCalculator;
use App\Domains\Commercial\AccountsPayable\SupplierBillDraftGuard;
use App\Domains\Commercial\AccountsPayable\SupplierBillPostingService;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierBillController extends Controller
{
    public function __construct(
        private SupplierBillCalculator $calculator,
        private SupplierBillDraftGuard $draftGuard,
        private SupplierBillPostingService $postingService
    ) {}

    /**
     * GET /api/supplier-bills?supplier_id=&status=&limit=&offset=
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(SupplierBill::query(), $tenantId)
            ->with(['supplier:id,name']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 200);
        $offset = max((int) $request->input('offset', 0), 0);

        $items = $query->orderByDesc('bill_date')
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json($items);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['required', 'string', 'in:CASH,CREDIT'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.project_id' => ['nullable', 'uuid'],
            'lines.*.crop_cycle_id' => ['nullable', 'uuid'],
            'lines.*.cost_category' => ['nullable', 'string', 'in:INPUT,SERVICE,REPAIR,OTHER'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'lines.*.cash_unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.credit_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Tenant isolation: supplier must belong to tenant.
        TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($validated['supplier_id']);

        $calc = $this->calculator->calculateBill($validated['payment_terms'], $validated['lines']);

        $bill = DB::transaction(function () use ($tenantId, $validated, $calc, $request) {
            $bill = SupplierBill::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $validated['supplier_id'],
                'reference_no' => $validated['reference_no'] ?? null,
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency_code' => strtoupper((string) ($validated['currency_code'] ?? 'GBP')),
                'payment_terms' => $validated['payment_terms'],
                'status' => SupplierBill::STATUS_DRAFT,
                'subtotal_cash_amount' => $calc['subtotal_cash_amount'],
                'credit_premium_total' => $calc['credit_premium_total'],
                'grand_total' => $calc['grand_total'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->header('X-User-Id'),
            ]);

            foreach ($calc['lines'] as $line) {
                SupplierBillLine::create([
                    'tenant_id' => $tenantId,
                    'supplier_bill_id' => $bill->id,
                    'line_no' => (int) $line['line_no'],
                    'description' => $line['description'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'crop_cycle_id' => $line['crop_cycle_id'] ?? null,
                    'cost_category' => $line['cost_category'] ?? 'OTHER',
                    'qty' => $line['qty'],
                    'cash_unit_price' => $line['cash_unit_price'],
                    'credit_unit_price' => $line['credit_unit_price'] ?? null,
                    'base_cash_amount' => $line['base_cash_amount'],
                    'selected_unit_price' => $line['selected_unit_price'],
                    'credit_premium_amount' => $line['credit_premium_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $bill;
        });

        return response()->json(
            $bill->fresh(['supplier:id,name', 'lines']),
            201
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $bill = TenantScoped::for(SupplierBill::query(), $tenantId)
            ->with(['supplier:id,name', 'lines'])
            ->findOrFail($id);

        return response()->json($bill);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        /** @var SupplierBill $bill */
        $bill = TenantScoped::for(SupplierBill::query(), $tenantId)
            ->with(['lines'])
            ->findOrFail($id);

        $this->draftGuard->assertDraft($bill);

        $validated = $request->validate([
            'supplier_id' => ['sometimes', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'bill_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'payment_terms' => ['sometimes', 'string', 'in:CASH,CREDIT'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.project_id' => ['nullable', 'uuid'],
            'lines.*.crop_cycle_id' => ['nullable', 'uuid'],
            'lines.*.cost_category' => ['nullable', 'string', 'in:INPUT,SERVICE,REPAIR,OTHER'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'lines.*.cash_unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.credit_unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $nextSupplierId = $validated['supplier_id'] ?? $bill->supplier_id;
        TenantScoped::for(Supplier::query(), $tenantId)->findOrFail($nextSupplierId);

        $nextTerms = $validated['payment_terms'] ?? $bill->payment_terms;
        $calc = $this->calculator->calculateBill($nextTerms, $validated['lines']);

        $bill = DB::transaction(function () use ($bill, $validated, $calc, $tenantId, $nextSupplierId, $nextTerms) {
            $bill->update([
                'supplier_id' => $nextSupplierId,
                'reference_no' => array_key_exists('reference_no', $validated) ? ($validated['reference_no'] ?? null) : $bill->reference_no,
                'bill_date' => $validated['bill_date'] ?? $bill->bill_date,
                'due_date' => array_key_exists('due_date', $validated) ? ($validated['due_date'] ?? null) : $bill->due_date,
                'currency_code' => isset($validated['currency_code']) ? strtoupper((string) $validated['currency_code']) : $bill->currency_code,
                'payment_terms' => $nextTerms,
                'subtotal_cash_amount' => $calc['subtotal_cash_amount'],
                'credit_premium_total' => $calc['credit_premium_total'],
                'grand_total' => $calc['grand_total'],
                'notes' => array_key_exists('notes', $validated) ? ($validated['notes'] ?? null) : $bill->notes,
            ]);

            SupplierBillLine::where('supplier_bill_id', $bill->id)->where('tenant_id', $tenantId)->delete();
            foreach ($calc['lines'] as $line) {
                SupplierBillLine::create([
                    'tenant_id' => $tenantId,
                    'supplier_bill_id' => $bill->id,
                    'line_no' => (int) $line['line_no'],
                    'description' => $line['description'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'crop_cycle_id' => $line['crop_cycle_id'] ?? null,
                    'cost_category' => $line['cost_category'] ?? 'OTHER',
                    'qty' => $line['qty'],
                    'cash_unit_price' => $line['cash_unit_price'],
                    'credit_unit_price' => $line['credit_unit_price'] ?? null,
                    'base_cash_amount' => $line['base_cash_amount'],
                    'selected_unit_price' => $line['selected_unit_price'],
                    'credit_premium_amount' => $line['credit_premium_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $bill;
        });

        return response()->json($bill->fresh(['supplier:id,name', 'lines']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $bill = TenantScoped::for(SupplierBill::query(), $tenantId)->findOrFail($id);
        $this->draftGuard->assertDraft($bill);

        $bill->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/supplier-bills/{id}/post
     */
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

