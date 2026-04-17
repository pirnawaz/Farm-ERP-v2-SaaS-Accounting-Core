<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\Payables\SupplierCreditNote;
use App\Domains\Commercial\Payables\SupplierCreditNotePostingService;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\CostCenter;
use App\Models\InvGrn;
use App\Models\Party;
use App\Models\Project;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierCreditNoteController extends Controller
{
    public function __construct(
        private SupplierCreditNotePostingService $postingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $q = TenantScoped::for(SupplierCreditNote::query(), $tenantId)
            ->with(['party:id,name', 'supplierInvoice:id,reference_no,total_amount', 'postingGroup:id,posting_date']);

        if ($request->filled('party_id')) {
            $q->where('party_id', $request->input('party_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->input('status'));
        }

        return response()->json($q->orderByDesc('created_at')->limit(200)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'party_id' => ['required', 'uuid'],
            'supplier_invoice_id' => ['nullable', 'uuid'],
            'inv_grn_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'credit_date' => ['required', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        Party::where('id', $validated['party_id'])->where('tenant_id', $tenantId)->firstOrFail();

        if (! empty($validated['supplier_invoice_id'])) {
            $inv = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($validated['supplier_invoice_id']);
            if ((string) $inv->party_id !== (string) $validated['party_id']) {
                throw ValidationException::withMessages([
                    'party_id' => ['Supplier must match the linked bill.'],
                ]);
            }
            $validated['project_id'] = $inv->project_id;
            $validated['cost_center_id'] = $inv->cost_center_id;
        } else {
            $hasP = ! empty($validated['project_id']);
            $hasC = ! empty($validated['cost_center_id']);
            if ($hasP === $hasC) {
                throw ValidationException::withMessages([
                    'scope' => ['Without a linked bill, provide exactly one of project_id or cost_center_id.'],
                ]);
            }
            if ($hasP) {
                TenantScoped::for(Project::query(), $tenantId)->findOrFail($validated['project_id']);
            }
            if ($hasC) {
                TenantScoped::for(CostCenter::query(), $tenantId)->findOrFail($validated['cost_center_id']);
            }
        }

        if (! empty($validated['inv_grn_id'])) {
            $grn = InvGrn::where('tenant_id', $tenantId)->where('id', $validated['inv_grn_id'])->firstOrFail();
            if ($grn->supplier_party_id && (string) $grn->supplier_party_id !== (string) $validated['party_id']) {
                throw ValidationException::withMessages([
                    'inv_grn_id' => ['GRN supplier must match the credit note supplier.'],
                ]);
            }
        }

        $note = SupplierCreditNote::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'status' => SupplierCreditNote::STATUS_DRAFT,
            'created_by' => $request->header('X-User-Id'),
        ]));

        return response()->json($note->load(['party:id,name', 'supplierInvoice:id,reference_no']), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $note = TenantScoped::for(SupplierCreditNote::query(), $tenantId)
            ->with(['party:id,name', 'supplierInvoice', 'invGrn:id,doc_no', 'project:id,name', 'costCenter:id,name,code', 'postingGroup'])
            ->findOrFail($id);

        return response()->json($note);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $note = TenantScoped::for(SupplierCreditNote::query(), $tenantId)->findOrFail($id);
        if ($note->status !== SupplierCreditNote::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft credit notes can be updated.'], 422);
        }

        $validated = $request->validate([
            'party_id' => ['sometimes', 'uuid'],
            'supplier_invoice_id' => ['nullable', 'uuid'],
            'inv_grn_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'credit_date' => ['sometimes', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'total_amount' => ['sometimes', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);

        if (array_key_exists('party_id', $validated)) {
            Party::where('id', $validated['party_id'])->where('tenant_id', $tenantId)->firstOrFail();
        }

        $merged = array_merge($note->only([
            'party_id', 'supplier_invoice_id', 'inv_grn_id', 'project_id', 'cost_center_id',
            'reference_no', 'credit_date', 'currency_code', 'total_amount', 'notes',
        ]), $validated);

        if (! empty($merged['supplier_invoice_id'])) {
            $inv = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($merged['supplier_invoice_id']);
            if ((string) $inv->party_id !== (string) $merged['party_id']) {
                throw ValidationException::withMessages([
                    'party_id' => ['Supplier must match the linked bill.'],
                ]);
            }
            $merged['project_id'] = $inv->project_id;
            $merged['cost_center_id'] = $inv->cost_center_id;
        } else {
            $hasP = ! empty($merged['project_id']);
            $hasC = ! empty($merged['cost_center_id']);
            if ($hasP === $hasC) {
                throw ValidationException::withMessages([
                    'scope' => ['Without a linked bill, provide exactly one of project_id or cost_center_id.'],
                ]);
            }
        }

        if (! empty($merged['inv_grn_id'])) {
            $grn = InvGrn::where('tenant_id', $tenantId)->where('id', $merged['inv_grn_id'])->firstOrFail();
            if ($grn->supplier_party_id && (string) $grn->supplier_party_id !== (string) $merged['party_id']) {
                throw ValidationException::withMessages([
                    'inv_grn_id' => ['GRN supplier must match the credit note supplier.'],
                ]);
            }
        }

        $note->update($merged);

        return response()->json($note->fresh(['party:id,name', 'supplierInvoice:id,reference_no']));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $note = TenantScoped::for(SupplierCreditNote::query(), $tenantId)->findOrFail($id);
        if ($note->status !== SupplierCreditNote::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft credit notes can be deleted.'], 422);
        }
        $note->delete();

        return response()->json(null, 204);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'posting_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $pg = $this->postingService->post(
            $id,
            $tenantId,
            Carbon::parse($validated['posting_date'])->format('Y-m-d'),
            $validated['idempotency_key'] ?? null
        );

        return response()->json($pg, 201);
    }
}
