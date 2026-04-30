<?php

namespace App\Http\Controllers;

use App\Domains\Commercial\AccountsPayable\Reports\ApAgingReportQuery;
use App\Domains\Commercial\AccountsPayable\Reports\CreditPremiumByProjectReportQuery;
use App\Domains\Commercial\AccountsPayable\Reports\SupplierLedgerReportQuery;
use App\Domains\Commercial\AccountsPayable\Reports\UnpaidSupplierBillsReportQuery;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AP-4: Read-only Procurement + AP reports.
 * IMPORTANT: Must not mutate accounting artifacts or create missing data.
 */
class ApReportsController extends Controller
{
    public function __construct(
        private SupplierLedgerReportQuery $supplierLedger,
        private UnpaidSupplierBillsReportQuery $unpaidBills,
        private ApAgingReportQuery $aging,
        private CreditPremiumByProjectReportQuery $creditPremium
    ) {}

    public function supplierLedger(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'party_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->supplierLedger->run($tenantId, $validated['party_id'], $validated['from'] ?? null, $validated['to'] ?? null)
        );
    }

    public function unpaidBills(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'party_id' => ['nullable', 'uuid'],
            'crop_cycle_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'as_of' => ['nullable', 'date'],
        ]);

        return response()->json([
            'rows' => $this->unpaidBills->run($tenantId, $validated),
        ]);
    }

    public function apAging(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'as_of' => ['required', 'date'],
            'party_id' => ['nullable', 'uuid'],
        ]);

        return response()->json(
            $this->aging->run($tenantId, $validated['as_of'], ['party_id' => $validated['party_id'] ?? null])
        );
    }

    public function creditPremiumByProject(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'party_id' => ['nullable', 'uuid'],
            'crop_cycle_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
        ]);

        return response()->json([
            'rows' => $this->creditPremium->run($tenantId, $validated),
        ]);
    }
}

