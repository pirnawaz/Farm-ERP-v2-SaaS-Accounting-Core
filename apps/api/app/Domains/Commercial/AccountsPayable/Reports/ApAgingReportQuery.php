<?php

namespace App\Domains\Commercial\AccountsPayable\Reports;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Services\BillPaymentService;
use App\Support\TenantScoped;

final class ApAgingReportQuery
{
    public function __construct(
        private BillPaymentService $billPaymentService
    ) {}

    /**
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array<string,string>}
     */
    public function run(string $tenantId, string $asOf, array $filters = []): array
    {
        $partyId = $filters['party_id'] ?? null;

        $q = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['party:id,name'])
            ->whereIn('status', ['POSTED', 'PAID']);

        if ($partyId) {
            $q->where('party_id', $partyId);
        }

        $bucketByParty = [];
        foreach ($q->get() as $inv) {
            $outstanding = (float) $this->billPaymentService->getSupplierInvoiceOutstanding($inv->id, $tenantId);
            if ($outstanding <= 0.009) {
                continue;
            }

            $due = $inv->due_date?->toDateString() ?: ($inv->invoice_date?->toDateString());
            if (! $due) {
                continue;
            }
            $days = (int) (new \DateTime($asOf))->diff(new \DateTime($due))->format('%r%a');
            // days <= 0 means not overdue (current)
            $bucket = 'current';
            if ($days >= 1 && $days <= 30) $bucket = 'd1_30';
            elseif ($days >= 31 && $days <= 60) $bucket = 'd31_60';
            elseif ($days >= 61 && $days <= 90) $bucket = 'd61_90';
            elseif ($days >= 91) $bucket = 'd90_plus';

            $pid = (string) $inv->party_id;
            if (! isset($bucketByParty[$pid])) {
                $bucketByParty[$pid] = [
                    'party_id' => $pid,
                    'supplier_name' => $inv->party?->name,
                    'current' => 0.0,
                    'd1_30' => 0.0,
                    'd31_60' => 0.0,
                    'd61_90' => 0.0,
                    'd90_plus' => 0.0,
                    'total_outstanding' => 0.0,
                ];
            }
            $bucketByParty[$pid][$bucket] += round($outstanding, 2);
            $bucketByParty[$pid]['total_outstanding'] += round($outstanding, 2);
        }

        $mapped = array_values(array_map(function ($r) {
            return [
                'party_id' => $r['party_id'],
                'supplier_name' => $r['supplier_name'],
                'current' => number_format((float) $r['current'], 2, '.', ''),
                'd1_30' => number_format((float) $r['d1_30'], 2, '.', ''),
                'd31_60' => number_format((float) $r['d31_60'], 2, '.', ''),
                'd61_90' => number_format((float) $r['d61_90'], 2, '.', ''),
                'd90_plus' => number_format((float) $r['d90_plus'], 2, '.', ''),
                'total_outstanding' => number_format((float) $r['total_outstanding'], 2, '.', ''),
            ];
        }, $bucketByParty));

        $totals = [
            'current' => number_format(array_sum(array_map(fn ($x) => (float) $x['current'], $mapped)), 2, '.', ''),
            'd1_30' => number_format(array_sum(array_map(fn ($x) => (float) $x['d1_30'], $mapped)), 2, '.', ''),
            'd31_60' => number_format(array_sum(array_map(fn ($x) => (float) $x['d31_60'], $mapped)), 2, '.', ''),
            'd61_90' => number_format(array_sum(array_map(fn ($x) => (float) $x['d61_90'], $mapped)), 2, '.', ''),
            'd90_plus' => number_format(array_sum(array_map(fn ($x) => (float) $x['d90_plus'], $mapped)), 2, '.', ''),
            'total_outstanding' => number_format(array_sum(array_map(fn ($x) => (float) $x['total_outstanding'], $mapped)), 2, '.', ''),
        ];

        return [
            'as_of' => $asOf,
            'rows' => $mapped,
            'totals' => $totals,
        ];
    }
}

