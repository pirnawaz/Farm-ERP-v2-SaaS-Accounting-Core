<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoicePostingService;
use App\Http\Requests\PostSupplierInvoiceRequest;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierInvoiceController extends Controller
{
    public function __construct(
        private SupplierInvoicePostingService $postingService
    ) {}

    /**
     * GET /api/supplier-invoices?party_id=&status=&limit=&offset=
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $query = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['party:id,name', 'project:id,name', 'postingGroup:id,posting_date']);

        if ($request->filled('party_id')) {
            $query->where('party_id', $request->input('party_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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
     * GET /api/supplier-invoices/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with([
                'party:id,name,party_types',
                'project:id,name',
                'grn:id,doc_no,posting_date',
                'postingGroup:id,posting_date',
                'lines',
            ])
            ->findOrFail($id);

        return response()->json($invoice);
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
