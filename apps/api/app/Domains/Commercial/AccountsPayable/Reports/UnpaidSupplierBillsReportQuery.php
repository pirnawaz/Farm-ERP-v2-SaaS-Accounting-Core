<?php

namespace App\Domains\Commercial\AccountsPayable\Reports;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Services\BillPaymentService;
use App\Support\TenantScoped;
use Illuminate\Support\Facades\DB;

final class UnpaidSupplierBillsReportQuery
{
    public function __construct(
        private BillPaymentService $billPaymentService
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(string $tenantId, array $filters): array
    {
        $partyId = $filters['party_id'] ?? null;
        $cropCycleId = $filters['crop_cycle_id'] ?? null;
        $projectId = $filters['project_id'] ?? null;
        $asOf = $filters['as_of'] ?? null;

        $q = TenantScoped::for(SupplierInvoice::query(), $tenantId)
            ->with(['party:id,name', 'project:id,name', 'postingGroup:id,posting_date,crop_cycle_id'])
            ->whereIn('status', ['POSTED', 'PAID']);

        if ($partyId) {
            $q->where('party_id', $partyId);
        }
        if ($projectId) {
            $q->where('project_id', $projectId);
        }
        if ($cropCycleId) {
            $q->whereHas('postingGroup', fn ($pg) => $pg->where('crop_cycle_id', $cropCycleId));
        }
        if ($asOf) {
            $q->where('invoice_date', '<=', $asOf);
        }

        $rows = [];
        foreach ($q->orderBy('due_date')->orderBy('invoice_date')->get() as $inv) {
            $outstanding = (float) $this->billPaymentService->getSupplierInvoiceOutstanding($inv->id, $tenantId);
            if ($outstanding <= 0.009) {
                continue;
            }
            $paid = max(0.0, round((float) $inv->total_amount - $outstanding, 2));
            $rows[] = [
                'supplier_invoice_id' => $inv->id,
                'reference_no' => $inv->reference_no,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'due_date' => $inv->due_date?->toDateString(),
                'currency_code' => $inv->currency_code,
                'status' => $inv->status,
                'total' => number_format((float) $inv->total_amount, 2, '.', ''),
                'paid' => number_format($paid, 2, '.', ''),
                'unpaid' => number_format($outstanding, 2, '.', ''),
                'party_id' => $inv->party_id,
                'supplier_name' => $inv->party?->name,
                'project_id' => $inv->project_id,
                'crop_cycle_id' => $inv->postingGroup?->crop_cycle_id,
            ];
        }

        return $rows;
    }
}

