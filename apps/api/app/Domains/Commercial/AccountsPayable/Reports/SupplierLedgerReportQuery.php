<?php

namespace App\Domains\Commercial\AccountsPayable\Reports;

use Illuminate\Support\Facades\DB;

final class SupplierLedgerReportQuery
{
    /**
     * Canonical AP ledger: supplier invoices + supplier payment allocations (old AP flow).
     *
     * @return array{from: ?string, to: ?string, party_id: string, supplier_name: string, rows: list<array<string,mixed>>, totals: array<string,string>}
     */
    public function run(string $tenantId, string $partyId, ?string $from = null, ?string $to = null): array
    {
        $party = DB::table('parties')->where('tenant_id', $tenantId)->where('id', $partyId)->first();
        if (! $party) {
            abort(404, 'Party not found');
        }

        $invQ = DB::table('supplier_invoices as si')
            ->join('posting_groups as pg', 'pg.id', '=', 'si.posting_group_id')
            ->where('si.tenant_id', $tenantId)
            ->where('si.party_id', $partyId)
            ->whereIn('si.status', ['POSTED', 'PAID']);
        if ($from) $invQ->where('pg.posting_date', '>=', $from);
        if ($to) $invQ->where('pg.posting_date', '<=', $to);

        $allocQ = DB::table('supplier_payment_allocations as spa')
            ->join('payments as pay', 'pay.id', '=', 'spa.payment_id')
            ->join('supplier_invoices as si', 'si.id', '=', 'spa.supplier_invoice_id')
            ->where('spa.tenant_id', $tenantId)
            ->where('si.tenant_id', $tenantId)
            ->where('si.party_id', $partyId)
            ->where(function ($q) {
                $q->where('spa.status', 'ACTIVE')->orWhereNull('spa.status');
            });
        if ($from) $allocQ->where('spa.allocation_date', '>=', $from);
        if ($to) $allocQ->where('spa.allocation_date', '<=', $to);

        $invoices = $invQ->selectRaw("
                pg.posting_date as txn_date,
                'INVOICE' as txn_type,
                si.id as ref_id,
                si.reference_no as reference_no,
                si.total_amount::numeric as credit_amount,
                0::numeric as debit_amount
            ")->get();

        $payments = $allocQ->selectRaw("
                spa.allocation_date as txn_date,
                'PAYMENT' as txn_type,
                spa.payment_id as ref_id,
                pay.reference as reference_no,
                0::numeric as credit_amount,
                spa.amount::numeric as debit_amount
            ")->get();

        $rows = $invoices->concat($payments)
            ->sortBy([
                ['txn_date', 'asc'],
                ['txn_type', 'asc'], // BILL before PAYMENT on same date
                ['ref_id', 'asc'],
            ])
            ->values()
            ->all();

        $running = 0.0;
        $out = [];
        $totalCredits = 0.0;
        $totalDebits = 0.0;
        foreach ($rows as $r) {
            $cr = (float) $r->credit_amount;
            $dr = (float) $r->debit_amount;
            $running += ($cr - $dr);
            $totalCredits += $cr;
            $totalDebits += $dr;
            $out[] = [
                'date' => $r->txn_date,
                'type' => $r->txn_type,
                'reference' => $r->reference_no,
                'ref_id' => $r->ref_id,
                'debit' => number_format($dr, 2, '.', ''),
                'credit' => number_format($cr, 2, '.', ''),
                'running_balance' => number_format($running, 2, '.', ''),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'party_id' => $partyId,
            'supplier_name' => $party->name,
            'rows' => $out,
            'totals' => [
                'total_debit' => number_format($totalDebits, 2, '.', ''),
                'total_credit' => number_format($totalCredits, 2, '.', ''),
                'ending_balance' => number_format($running, 2, '.', ''),
            ],
        ];
    }
}

