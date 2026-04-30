<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\Procurement\SupplierInvoicePoMatchService;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\SupplierInvoiceLinePoMatch;
use App\Services\TenantContext;
use App\Support\TenantScoped;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierInvoicePoMatchesController extends Controller
{
    public function __construct(
        private SupplierInvoicePoMatchService $service
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['lines'])
            ->findOrFail($id);

        $lineIds = $invoice->lines->pluck('id')->all();
        $matches = SupplierInvoiceLinePoMatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('supplier_invoice_line_id', $lineIds)
            ->with(['purchaseOrderLine.purchaseOrder'])
            ->get();

        return response()->json([
            'supplier_invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'matches' => $matches,
        ]);
    }

    public function sync(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $invoice = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['lines'])
            ->findOrFail($id);

        $validated = $request->validate([
            'matches' => ['required', 'array'],
            'matches.*.supplier_invoice_line_id' => ['required', 'uuid'],
            'matches.*.purchase_order_line_id' => ['required', 'uuid'],
            'matches.*.matched_qty' => ['required', 'numeric', 'gt:0'],
            'matches.*.matched_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $this->service->syncMatches($invoice, $validated['matches'], $tenantId);

        $lineIds = $invoice->lines->pluck('id')->all();
        $matches = SupplierInvoiceLinePoMatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('supplier_invoice_line_id', $lineIds)
            ->with(['purchaseOrderLine.purchaseOrder'])
            ->get();

        return response()->json([
            'supplier_invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'matches' => $matches,
        ]);
    }
}

