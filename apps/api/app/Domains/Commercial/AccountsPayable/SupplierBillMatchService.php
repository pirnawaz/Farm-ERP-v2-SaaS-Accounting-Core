<?php

namespace App\Domains\Commercial\AccountsPayable;

use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBill;
use App\Models\SupplierBillLineMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PO/GRN matching for Supplier Bills (traceability only; no ledger impact).
 *
 * Data safety: reads GRNs, does not rewrite inventory history.
 */
final class SupplierBillMatchService
{
    /**
     * Replace all matches for lines on this bill with the given set.
     *
     * @param  list<array{supplier_bill_line_id: string, purchase_order_line_id?: ?string, grn_line_id?: ?string, matched_qty: float|string, matched_amount: float|string}>  $matches
     */
    public function syncMatches(SupplierBill $bill, array $matches, string $tenantId): void
    {
        if (! in_array($bill->status, [SupplierBill::STATUS_DRAFT, SupplierBill::STATUS_POSTED, SupplierBill::STATUS_PARTIALLY_PAID, SupplierBill::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'status' => ['Matches are only allowed for draft, posted, or paid supplier bills.'],
            ]);
        }

        DB::transaction(function () use ($bill, $matches, $tenantId) {
            $bill->load(['lines', 'supplier']);
            $lineIds = $bill->lines->pluck('id')->all();

            SupplierBillLineMatch::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('supplier_bill_line_id', $lineIds)
                ->delete();

            if ($matches === []) {
                return;
            }

            $seenPairs = [];
            foreach ($matches as $i => $row) {
                $lineId = $row['supplier_bill_line_id'] ?? null;
                $poLineId = $row['purchase_order_line_id'] ?? null;
                $grnLineId = $row['grn_line_id'] ?? null;

                if (! $lineId) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['supplier_bill_line_id is required.'],
                    ]);
                }
                if (! $poLineId && ! $grnLineId) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['purchase_order_line_id or grn_line_id is required.'],
                    ]);
                }

                $pairKey = $lineId . ':' . ($grnLineId ?? '-') . ':' . ($poLineId ?? '-');
                if (isset($seenPairs[$pairKey])) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Duplicate match row in payload.'],
                    ]);
                }
                $seenPairs[$pairKey] = true;

                if (! in_array($lineId, $lineIds, true)) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Line does not belong to this supplier bill.'],
                    ]);
                }

                $qty = round((float) ($row['matched_qty'] ?? 0), 6);
                $amt = round((float) ($row['matched_amount'] ?? 0), 2);
                if ($qty <= 0 || $amt <= 0) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['matched_qty and matched_amount must be positive.'],
                    ]);
                }

                // Validate GRN supplier + posted status when present.
                if ($grnLineId) {
                    /** @var InvGrnLine $grnLine */
                    $grnLine = InvGrnLine::query()->where('tenant_id', $tenantId)->where('id', $grnLineId)->firstOrFail();
                    /** @var InvGrn $grn */
                    $grn = InvGrn::query()->where('tenant_id', $tenantId)->where('id', $grnLine->grn_id)->firstOrFail();
                    if ($grn->status !== 'POSTED') {
                        throw ValidationException::withMessages([
                            "matches.{$i}" => ['GRN must be posted before matching.'],
                        ]);
                    }
                    // Suppliers table is separate from parties; for now only enforce tenant and allow match even if GRN has no supplier_party_id.
                    // (We avoid hard link between Supplier and Party in AP-5.)
                }

                // Validate PO line belongs to tenant and is approved-ish.
                if ($poLineId) {
                    /** @var PurchaseOrderLine $poLine */
                    $poLine = PurchaseOrderLine::query()->where('tenant_id', $tenantId)->where('id', $poLineId)->firstOrFail();
                    /** @var PurchaseOrder $po */
                    $po = PurchaseOrder::query()->where('tenant_id', $tenantId)->where('id', $poLine->purchase_order_id)->firstOrFail();
                    if (! in_array($po->status, [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED], true)) {
                        throw ValidationException::withMessages([
                            "matches.{$i}" => ['Purchase Order must be approved before matching.'],
                        ]);
                    }

                    // Overbilling prevention (qty-based).
                    $ordered = (float) $poLine->qty_ordered;
                    $tol = (float) $poLine->qty_overbill_tolerance;

                    $sumOtherBillsForPoLine = (float) SupplierBillLineMatch::query()
                        ->where('tenant_id', $tenantId)
                        ->where('purchase_order_line_id', $poLineId)
                        ->whereHas('supplierBillLine', function ($q) use ($bill) {
                            $q->whereHas('bill', fn ($q2) => $q2->where('id', '!=', $bill->id)->whereNotIn('status', [SupplierBill::STATUS_CANCELLED]));
                        })
                        ->sum('matched_qty');

                    $sumThisPayloadForPoLine = 0.0;
                    foreach ($matches as $r2) {
                        if (($r2['purchase_order_line_id'] ?? null) === $poLineId) {
                            $sumThisPayloadForPoLine += round((float) ($r2['matched_qty'] ?? 0), 6);
                        }
                    }

                    if ($sumOtherBillsForPoLine + $sumThisPayloadForPoLine - ($ordered + $tol) > 0.000001) {
                        throw ValidationException::withMessages([
                            "matches.{$i}" => ['Matched billed quantity exceeds PO ordered quantity (plus tolerance).'],
                        ]);
                    }
                }

                SupplierBillLineMatch::create([
                    'tenant_id' => $tenantId,
                    'supplier_bill_line_id' => $lineId,
                    'purchase_order_line_id' => $poLineId,
                    'grn_line_id' => $grnLineId,
                    'matched_qty' => $qty,
                    'matched_amount' => $amt,
                ]);
            }
        });
    }
}

