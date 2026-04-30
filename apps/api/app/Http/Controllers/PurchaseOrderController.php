<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\Procurement\PurchaseOrderService;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private PurchaseOrderService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $q = PurchaseOrder::query()
            ->where('tenant_id', $tenantId)
            ->with(['supplier', 'lines.item'])
            ->orderBy('po_date', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $q->where('supplier_id', $request->supplier_id);
        }

        return response()->json($q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'po_no' => ['required', 'string', 'max:100'],
            'po_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.qty_ordered' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.qty_overbill_tolerance' => ['nullable', 'numeric', 'min:0'],
            'lines.*.expected_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        Supplier::query()->where('tenant_id', $tenantId)->where('id', $validated['supplier_id'])->firstOrFail();

        $po = $this->service->create($tenantId, $validated, $request->header('X-User-Id'));
        return response()->json($po, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $po = PurchaseOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['supplier', 'lines.item'])
            ->firstOrFail();
        return response()->json($po);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'po_no' => ['nullable', 'string', 'max:100'],
            'po_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.line_no' => ['nullable', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.qty_ordered' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.qty_overbill_tolerance' => ['nullable', 'numeric', 'min:0'],
            'lines.*.expected_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $po = $this->service->update($po, $tenantId, $validated);
        return response()->json($po);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        if (! $po->canBeUpdated()) {
            return response()->json(['message' => 'Only DRAFT purchase orders can be deleted.'], 422);
        }
        $po->delete();
        return response()->json(['ok' => true]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
        $po = $this->service->approve($po, $tenantId, $request->header('X-User-Id'));
        return response()->json($po);
    }

    /**
     * Read-only helper to prefill a supplier invoice draft from a PO.
     */
    public function prepareInvoice(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $po = PurchaseOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['supplier'])
            ->firstOrFail();

        $partyId = $po->supplier?->party_id;
        if (! $partyId) {
            return response()->json(['message' => 'Purchase Order supplier is missing party_id; cannot prepare invoice.'], 422);
        }

        $currency = Tenant::query()->where('id', $tenantId)->value('currency_code') ?: 'GBP';

        $lines = DB::table('purchase_order_lines as pol')
            ->where('pol.tenant_id', $tenantId)
            ->where('pol.purchase_order_id', $po->id)
            ->selectRaw("
                pol.id,
                pol.line_no,
                pol.item_id,
                pol.description,
                pol.qty_ordered::numeric as qty_ordered,
                pol.qty_overbill_tolerance::numeric as qty_overbill_tolerance,
                pol.expected_unit_cost::numeric as expected_unit_cost
            ")
            ->orderBy('pol.line_no')
            ->get();

        $lineIds = $lines->pluck('id')->all();

        $receivedByLine = DB::table('purchase_order_receipt_matches as prm')
            ->where('prm.tenant_id', $tenantId)
            ->whereIn('prm.purchase_order_line_id', $lineIds)
            ->selectRaw('prm.purchase_order_line_id, SUM(prm.matched_qty::numeric) as received_qty')
            ->groupBy('prm.purchase_order_line_id')
            ->get()
            ->keyBy('purchase_order_line_id');

        $invoicedByLine = DB::table('supplier_invoice_line_po_matches as sipm')
            ->join('supplier_invoice_lines as sil', 'sil.id', '=', 'sipm.supplier_invoice_line_id')
            ->join('supplier_invoices as si', 'si.id', '=', 'sil.supplier_invoice_id')
            ->where('sipm.tenant_id', $tenantId)
            ->where('sil.tenant_id', $tenantId)
            ->where('si.tenant_id', $tenantId)
            ->whereIn('sipm.purchase_order_line_id', $lineIds)
            ->whereIn('si.status', ['POSTED', 'PAID'])
            ->selectRaw('sipm.purchase_order_line_id, SUM(sipm.matched_qty::numeric) as invoiced_qty')
            ->groupBy('sipm.purchase_order_line_id')
            ->get()
            ->keyBy('purchase_order_line_id');

        $outLines = $lines->map(function ($l) use ($receivedByLine, $invoicedByLine) {
            $ordered = (float) $l->qty_ordered;
            $tol = (float) $l->qty_overbill_tolerance;
            $received = (float) ($receivedByLine[$l->id]->received_qty ?? 0);
            $invoiced = (float) ($invoicedByLine[$l->id]->invoiced_qty ?? 0);
            $remaining = max(0.0, ($ordered + $tol) - $invoiced);
            $unitPrice = (float) ($l->expected_unit_cost ?? 0);

            return [
                'purchase_order_line_id' => $l->id,
                'line_no' => (int) $l->line_no,
                'item_id' => $l->item_id,
                'description' => $l->description,
                'qty_ordered' => number_format($ordered, 6, '.', ''),
                'qty_received' => number_format($received, 6, '.', ''),
                'qty_invoiced' => number_format($invoiced, 6, '.', ''),
                'remaining_qty' => number_format($remaining, 6, '.', ''),
                'unit_price' => number_format($unitPrice, 6, '.', ''),
            ];
        })->values()->all();

        return response()->json([
            'purchase_order_id' => $po->id,
            'po_no' => $po->po_no,
            'party_id' => $partyId,
            'currency_code' => strtoupper((string) $currency),
            'lines' => $outLines,
        ]);
    }
}

