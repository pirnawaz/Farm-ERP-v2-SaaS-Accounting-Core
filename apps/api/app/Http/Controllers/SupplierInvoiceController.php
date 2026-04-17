<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceDraftService;
use App\Domains\Commercial\Payables\SupplierInvoiceMatchService;
use App\Domains\Commercial\Payables\SupplierInvoicePostingService;
use App\Http\Requests\PostSupplierInvoiceRequest;
use App\Services\BillPaymentService;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private SupplierInvoicePostingService $postingService,
        private SupplierInvoiceDraftService $draftService,
        private SupplierInvoiceMatchService $matchService,
        private BillPaymentService $billPaymentService
    ) {}

    /**
     * GET /api/supplier-invoices?party_id=&status=&billing_scope=&limit=&offset=
     * billing_scope: farm | project | all (default all)
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['party:id,name', 'project:id,name', 'costCenter:id,name,code', 'postingGroup:id,posting_date']);

        if ($request->filled('party_id')) {
            $query->where('party_id', $request->input('party_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $scope = $request->input('billing_scope', 'all');
        if ($scope === 'farm') {
            $query->whereNotNull('cost_center_id')->whereNull('project_id');
        } elseif ($scope === 'project') {
            $query->whereNotNull('project_id')->whereNull('cost_center_id');
        }

        $limit = min(max((int) $request->input('limit', 50), 1), 200);
        $offset = max((int) $request->input('offset', 0), 0);

        $items = $query->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json($items);
    }

    /**
     * POST /api/supplier-invoices
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'party_id' => ['required', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'grn_id' => ['nullable', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'subtotal_amount' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'total_amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.qty' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.line_total' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_amount' => ['nullable', 'numeric'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.grn_line_id' => ['nullable', 'uuid'],
        ]);

        $invoice = $this->draftService->create(
            $tenantId,
            $validated,
            $validated['lines'],
            $request->header('X-User-Id')
        );

        return response()->json($invoice, 201);
    }

    /**
     * GET /api/supplier-invoices/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with([
                'party:id,name,party_types',
                'project:id,name',
                'costCenter:id,name,code,status',
                'grn:id,doc_no,posting_date',
                'postingGroup:id,posting_date',
                'lines',
            ])
            ->findOrFail($id);

        $payload = $invoice->toArray();
        $payload['billing_scope'] = $invoice->cost_center_id && ! $invoice->project_id
            ? 'farm_overhead'
            : ($invoice->project_id ? 'project' : 'unspecified');

        $tenantId = TenantContext::getTenantId($request);
        $payload['ap_match_summary'] = $this->matchService->summarizeForInvoice($invoice, $tenantId);
        if (in_array($invoice->status, [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID], true) && $invoice->posting_group_id) {
            $payload['outstanding_amount'] = number_format(
                $this->billPaymentService->getSupplierInvoiceOutstanding($invoice->id, $tenantId),
                2,
                '.',
                ''
            );
        }

        $payload['payment_applications'] = $this->billPaymentService->getSupplierInvoicePaymentApplications($invoice->id, $tenantId);

        return response()->json($payload);
    }

    /**
     * PUT /api/supplier-invoices/{id}/matches — replace GRN line ↔ bill line matches (traceability only).
     */
    public function syncMatches(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'matches' => ['required', 'array'],
            'matches.*.supplier_invoice_line_id' => ['required', 'uuid'],
            'matches.*.grn_line_id' => ['required', 'uuid'],
            'matches.*.matched_qty' => ['required', 'numeric', 'min:0.000001'],
            'matches.*.matched_amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $this->matchService->syncMatches($invoice, $validated['matches'], $tenantId);

        return response()->json($this->matchService->summarizeForInvoice($invoice->fresh(['lines']), $tenantId));
    }

    /**
     * PUT /api/supplier-invoices/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($id);

        $validated = $request->validate([
            'party_id' => ['sometimes', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'grn_id' => ['nullable', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'invoice_date' => ['sometimes', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'subtotal_amount' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'total_amount' => ['sometimes', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.qty' => ['nullable', 'numeric'],
            'lines.*.unit_price' => ['nullable', 'numeric'],
            'lines.*.line_total' => ['required', 'numeric', 'min:0.01'],
            'lines.*.tax_amount' => ['nullable', 'numeric'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.grn_line_id' => ['nullable', 'uuid'],
        ]);

        $invoice = $this->draftService->update($invoice, $validated, $validated['lines']);

        return response()->json($invoice);
    }

    /**
     * DELETE /api/supplier-invoices/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)->findOrFail($id);

        if ($invoice->status !== SupplierInvoice::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft bills can be deleted.'], 422);
        }

        $invoice->lines()->delete();
        $invoice->delete();

        return response()->json(null, 204);
    }

    public function post(PostSupplierInvoiceRequest $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);

        $tenantId = TenantContext::getTenantId($request);
        $postingGroup = $this->postingService->post(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key
        );

        return response()->json($postingGroup, 201);
    }
}
