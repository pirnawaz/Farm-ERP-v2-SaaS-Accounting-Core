<?php

namespace App\Domains\Commercial\Payables;

use App\Models\InvGrn;
use App\Models\InvGrnLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GRN ↔ supplier bill line matching for traceability (no ledger impact).
 */
class SupplierInvoiceMatchService
{
    /**
     * Replace all matches for lines on this invoice with the given set.
     *
     * @param  list<array{supplier_invoice_line_id: string, grn_line_id: string, matched_qty: float|string, matched_amount: float|string}>  $matches
     */
    public function syncMatches(SupplierInvoice $invoice, array $matches, string $tenantId): void
    {
        if (! in_array($invoice->status, [SupplierInvoice::STATUS_DRAFT, SupplierInvoice::STATUS_POSTED, SupplierInvoice::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'status' => ['Matches are only allowed for draft, posted, or paid supplier bills.'],
            ]);
        }

        DB::transaction(function () use ($invoice, $matches, $tenantId) {
            $invoice->load(['lines']);
            $lineIds = $invoice->lines->pluck('id')->all();

            SupplierInvoiceMatch::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('supplier_invoice_line_id', $lineIds)
                ->delete();

            if ($matches === []) {
                return;
            }

            $seenPairs = [];
            foreach ($matches as $i => $row) {
                $lineId = $row['supplier_invoice_line_id'] ?? null;
                $grnLineId = $row['grn_line_id'] ?? null;
                if (! $lineId || ! $grnLineId) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['supplier_invoice_line_id and grn_line_id are required.'],
                    ]);
                }
                $pairKey = $lineId . ':' . $grnLineId;
                if (isset($seenPairs[$pairKey])) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Duplicate line / GRN line pair in payload.'],
                    ]);
                }
                $seenPairs[$pairKey] = true;

                if (! in_array($lineId, $lineIds, true)) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Line does not belong to this supplier bill.'],
                    ]);
                }

                /** @var SupplierInvoiceLine $invLine */
                $invLine = $invoice->lines->firstWhere('id', $lineId);
                /** @var InvGrnLine $grnLine */
                $grnLine = InvGrnLine::query()->where('tenant_id', $tenantId)->where('id', $grnLineId)->firstOrFail();
                $grn = InvGrn::query()->where('tenant_id', $tenantId)->where('id', $grnLine->grn_id)->firstOrFail();

                if ($grn->status !== 'POSTED') {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['GRN must be posted before matching.'],
                    ]);
                }
                if (! $grn->supplier_party_id || (string) $grn->supplier_party_id !== (string) $invoice->party_id) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['GRN supplier must match the bill supplier.'],
                    ]);
                }

                $qty = round((float) $row['matched_qty'], 6);
                $amt = round((float) $row['matched_amount'], 2);
                if ($qty <= 0 || $amt <= 0) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['matched_qty and matched_amount must be positive.'],
                    ]);
                }

                $invLineCap = round((float) $invLine->line_total, 2);
                $grnLineCap = round((float) $grnLine->line_total, 2);

                $sumOtherInvForGrnLine = (float) SupplierInvoiceMatch::query()
                    ->where('tenant_id', $tenantId)
                    ->where('grn_line_id', $grnLineId)
                    ->whereHas('supplierInvoiceLine', fn ($q) => $q->where('supplier_invoice_id', '!=', $invoice->id))
                    ->sum('matched_amount');

                $sumThisPayloadSameInvLine = 0.0;
                $sumThisPayloadSameGrnLine = 0.0;
                foreach ($matches as $r2) {
                    if (($r2['supplier_invoice_line_id'] ?? '') === $lineId) {
                        $sumThisPayloadSameInvLine += round((float) ($r2['matched_amount'] ?? 0), 2);
                    }
                    if (($r2['grn_line_id'] ?? '') === $grnLineId) {
                        $sumThisPayloadSameGrnLine += round((float) ($r2['matched_amount'] ?? 0), 2);
                    }
                }

                if ($sumThisPayloadSameInvLine - $invLineCap > 0.02) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Matched amounts exceed this bill line total.'],
                    ]);
                }
                if ($sumOtherInvForGrnLine + $sumThisPayloadSameGrnLine - $grnLineCap > 0.02) {
                    throw ValidationException::withMessages([
                        "matches.{$i}" => ['Matched amounts exceed this GRN line value (across all bills).'],
                    ]);
                }

                SupplierInvoiceMatch::create([
                    'tenant_id' => $tenantId,
                    'supplier_invoice_line_id' => $lineId,
                    'grn_line_id' => $grnLineId,
                    'matched_qty' => $qty,
                    'matched_amount' => $amt,
                ]);
            }
        });
    }

    /**
     * @return array{matched_amount: float, unmatched_amount: float, matches: list<array<string, mixed>>}
     */
    public function summarizeForInvoice(SupplierInvoice $invoice, string $tenantId): array
    {
        $invoice->loadMissing('lines');
        $lineIds = $invoice->lines->pluck('id')->all();
        $rows = SupplierInvoiceMatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('supplier_invoice_line_id', $lineIds)
            ->with(['supplierInvoiceLine', 'grnLine.grn'])
            ->get();

        $matched = round((float) $rows->sum('matched_amount'), 2);
        $total = round((float) $invoice->total_amount, 2);
        $unmatched = max(0, round($total - $matched, 2));

        $matches = $rows->map(function (SupplierInvoiceMatch $m) {
            return [
                'id' => $m->id,
                'supplier_invoice_line_id' => $m->supplier_invoice_line_id,
                'grn_line_id' => $m->grn_line_id,
                'matched_qty' => (string) $m->matched_qty,
                'matched_amount' => (string) $m->matched_amount,
                'grn' => $m->grnLine?->grn ? [
                    'id' => $m->grnLine->grn->id,
                    'doc_no' => $m->grnLine->grn->doc_no,
                ] : null,
            ];
        })->values()->all();

        return [
            'matched_amount' => $matched,
            'unmatched_amount' => $unmatched,
            'matches' => $matches,
        ];
    }

    /**
     * @return array{matched_amount: float, unmatched_receipt_value: float, matched_bills: list<array<string, mixed>>}
     */
    public function summarizeForGrn(InvGrn $grn, string $tenantId): array
    {
        $lineIds = $grn->lines()->pluck('id')->all();
        $receiptTotal = round((float) $grn->lines()->sum('line_total'), 2);

        $rows = SupplierInvoiceMatch::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('grn_line_id', $lineIds)
            ->with(['supplierInvoiceLine.supplierInvoice'])
            ->get();

        $matched = round((float) $rows->sum('matched_amount'), 2);
        $unmatched = max(0, round($receiptTotal - $matched, 2));

        $bills = [];
        foreach ($rows->groupBy(fn ($m) => $m->supplierInvoiceLine?->supplier_invoice_id) as $invoiceId => $group) {
            if (! $invoiceId) {
                continue;
            }
            $inv = $group->first()->supplierInvoiceLine?->supplierInvoice;
            $bills[] = [
                'supplier_invoice_id' => $invoiceId,
                'reference_no' => $inv?->reference_no,
                'matched_amount' => (string) round((float) $group->sum('matched_amount'), 2),
            ];
        }

        return [
            'matched_amount' => $matched,
            'unmatched_receipt_value' => $unmatched,
            'matched_bills' => $bills,
        ];
    }
}
