<?php

namespace App\Domains\Commercial\Procurement;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierInvoiceLinePoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierInvoicePoMatchService
{
    /**
     * Replace all PO matches for lines on this invoice with the given set.
     *
     * Draft invoice matches may be saved, but PO rollups must count only POSTED/PAID invoices.
     *
     * @param  list<array{supplier_invoice_line_id: string, purchase_order_line_id: string, matched_qty: float|string, matched_amount: float|string}>  $matches
     */
    public function syncMatches(SupplierInvoice $invoice, array $matches, string $tenantId): void
    {
        if (! in_array($invoice->status, [SupplierInvoice::STATUS_DRAFT, SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'status' => ['Matches are only allowed for draft, posted, or paid supplier invoices.'],
            ]);
        }

        DB::transaction(function () use ($invoice, $matches, $tenantId) {
            $invoice->load(['lines']);
            $lineIds = $invoice->lines->pluck('id')->all();

            SupplierInvoiceLinePoMatch::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('supplier_invoice_line_id', $lineIds)
                ->delete();

            if ($matches === []) {
                return;
            }

            $seenPairs = [];
            foreach ($matches as $i => $row) {
                $lineId = $row['supplier_invoice_line_id'] ?? null;
                $poLineId = $row['purchase_order_line_id'] ?? null;
                if (! $lineId || ! $poLineId) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['supplier_invoice_line_id and purchase_order_line_id are required.'],
                    ]);
                }
                $pairKey = $lineId . ':' . $poLineId;
                if (isset($seenPairs[$pairKey])) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Duplicate invoice line / PO line pair in payload.'],
                    ]);
                }
                $seenPairs[$pairKey] = true;

                if (! in_array($lineId, $lineIds, true)) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Line does not belong to this supplier invoice.'],
                    ]);
                }

                /** @var PurchaseOrderLine $poLine */
                $poLine = PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('id', $poLineId)->firstOrFail();
                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $poLine->purchase_order_id)->firstOrFail();
                if (! in_array($po->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED], true)) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Purchase Order must be approved before matching.'],
                    ]);
                }

                $qty = round((float) ($row['matched_qty'] ?? 0), 6);
                $amt = round((float) ($row['matched_amount'] ?? 0), 2);
                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['matched_qty must be positive.'],
                    ]);
                }
                if ($amt < 0) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['matched_amount must be >= 0.'],
                    ]);
                }

                // Overbilling prevention counts only POSTED/PAID invoices in rollup.
                if (in_array($invoice->status, [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID], true)) {
                    $ordered = (float) $poLine->qty_ordered;
                    $tol = (float) $poLine->qty_overbill_tolerance;

                    $sumOtherPosted = (float) SupplierInvoiceLinePoMatch::query()
                        ->where('tenant_id', $tenantId)
                        ->where('purchase_order_line_id', $poLineId)
                        ->whereHas('supplierInvoiceLine', function ($q) use ($invoice) {
                            $q->whereHas('supplierInvoice', function ($q2) use ($invoice) {
                                $q2->where('id', '!=', $invoice->id)->whereIn('status', [SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID]);
                            });
                        })
                        ->sum('matched_qty');

                    $sumThisPayloadForPoLine = 0.0;
                    foreach ($matches as $r2) {
                        if (($r2['purchase_order_line_id'] ?? null) === $poLineId) {
                            $sumThisPayloadForPoLine += round((float) ($r2['matched_qty'] ?? 0), 6);
                        }
                    }

                    if ($sumOtherPosted + $sumThisPayloadForPoLine - ($ordered + $tol) > 0.000001) {
                        throw ValidationException::withMessages([
                            "matches.{$i}" => ['Matched invoiced quantity exceeds PO ordered quantity (plus tolerance).'],
                        ]);
                    }
                }

                SupplierInvoiceLinePoMatch::create([
                    'tenant_id' => $tenantId,
                    'supplier_invoice_line_id' => $lineId,
                    'purchase_order_line_id' => $poLineId,
                    'matched_qty' => $qty,
                    'matched_amount' => $amt,
                ]);
            }
        });
    }
}

