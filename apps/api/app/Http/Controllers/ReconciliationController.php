<?php

namespace App\Http\Controllers;

use App\Services\ReconciliationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReconciliationController extends Controller
{
    public function __construct(
        private ReconciliationService $reconciliationService
    ) {}

    /**
     * GET /api/reconciliation/project/:id
     * 
     * Returns reconciliation data for a project within a date range.
     * 
     * Query parameters:
     * - from: YYYY-MM-DD (optional, defaults to project crop cycle start)
     * - to: YYYY-MM-DD (required)
     * 
     * @param Request $request
     * @param string $id Project ID
     * @return JsonResponse
     */
    public function projectReconciliation(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fromDate = $request->input('from');
        $toDate = $request->input('to');

        // If from_date not provided, use a reasonable default (e.g., start of current year)
        if (!$fromDate) {
            $fromDate = date('Y-01-01');
        }

        try {
            // Get settlement vs OT reconciliation
            $settlementVsOT = $this->reconciliationService->reconcileProjectSettlementVsOT(
                $id,
                $tenantId,
                $fromDate,
                $toDate
            );

            // Get ledger reconciliation
            $ledgerReconciliation = $this->reconciliationService->reconcileProjectLedgerIncomeExpense(
                $id,
                $tenantId,
                $fromDate,
                $toDate
            );

            return response()->json([
                'project_id' => $id,
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                ],
                'settlement_vs_ot' => $settlementVsOT,
                'ledger_reconciliation' => $ledgerReconciliation,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/reconciliation/supplier/:party_id
     * 
     * Returns supplier AP reconciliation for a party within a date range.
     * 
     * Query parameters:
     * - from: YYYY-MM-DD (optional)
     * - to: YYYY-MM-DD (required)
     * 
     * @param Request $request
     * @param string $partyId Party ID
     * @return JsonResponse
     */
    public function supplierAPReconciliation(Request $request, string $partyId): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'from' => 'nullable|date',
            'to' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $fromDate = $request->input('from');
        $toDate = $request->input('to');

        // If from_date not provided, use a reasonable default
        if (!$fromDate) {
            $fromDate = date('Y-01-01');
        }

        try {
            $supplierAP = $this->reconciliationService->reconcileSupplierAP(
                $partyId,
                $tenantId,
                $fromDate,
                $toDate
            );

            return response()->json([
                'party_id' => $partyId,
                'supplier_ap' => $supplierAP,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
