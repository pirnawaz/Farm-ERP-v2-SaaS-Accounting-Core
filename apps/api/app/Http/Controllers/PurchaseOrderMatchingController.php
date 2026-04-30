<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderMatchingController extends Controller
{
    /**
     * Matching screen: ordered vs received (via PO↔GRN matches) vs billed (via SupplierInvoice↔PO matches).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $po = PurchaseOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['supplier'])
            ->firstOrFail();

        $lines = DB::table('purchase_order_lines as pol')
            ->where('pol.tenant_id', $tenantId)
            ->where('pol.purchase_order_id', $po->id)
            ->leftJoin('inv_items as ii', 'ii.id', '=', 'pol.item_id')
            ->selectRaw("
                pol.id,
                pol.line_no,
                pol.item_id,
                ii.name as item_name,
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

        $billedByLine = DB::table('supplier_invoice_line_po_matches as sipm')
            ->join('supplier_invoice_lines as sil', 'sil.id', '=', 'sipm.supplier_invoice_line_id')
            ->join('supplier_invoices as si', 'si.id', '=', 'sil.supplier_invoice_id')
            ->where('sipm.tenant_id', $tenantId)
            ->where('sil.tenant_id', $tenantId)
            ->where('si.tenant_id', $tenantId)
            ->whereIn('sipm.purchase_order_line_id', $lineIds)
            ->whereIn('si.status', ['POSTED', 'PAID'])
            ->selectRaw('sipm.purchase_order_line_id, SUM(sipm.matched_qty::numeric) as billed_qty')
            ->groupBy('sipm.purchase_order_line_id')
            ->get()
            ->keyBy('purchase_order_line_id');

        $outLines = $lines->map(function ($l) use ($receivedByLine, $billedByLine) {
            $ordered = (float) $l->qty_ordered;
            $tol = (float) $l->qty_overbill_tolerance;
            $received = (float) ($receivedByLine[$l->id]->received_qty ?? 0);
            $billed = (float) ($billedByLine[$l->id]->billed_qty ?? 0);
            $remainingToBill = max(0.0, ($ordered + $tol) - $billed);

            return [
                'purchase_order_line_id' => $l->id,
                'line_no' => (int) $l->line_no,
                'item_id' => $l->item_id,
                'item_name' => $l->item_name,
                'description' => $l->description,
                'qty_ordered' => number_format($ordered, 6, '.', ''),
                'qty_overbill_tolerance' => number_format($tol, 6, '.', ''),
                'qty_received' => number_format($received, 6, '.', ''),
                'qty_billed' => number_format($billed, 6, '.', ''),
                'qty_remaining_to_bill' => number_format($remainingToBill, 6, '.', ''),
            ];
        })->values()->all();

        return response()->json([
            'purchase_order' => [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'po_date' => $po->po_date?->toDateString(),
                'status' => $po->status,
                'supplier' => $po->supplier ? ['id' => $po->supplier->id, 'name' => $po->supplier->name] : null,
            ],
            'lines' => $outLines,
        ]);
    }
}

