<?php

namespace App\Http\Controllers;

use App\Domains\Reporting\Dashboard\DashboardSummaryService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Read-only dashboard endpoint. No ledger or posting mutations.
 */
class DashboardController extends Controller
{
    public function __construct(
        private DashboardSummaryService $dashboardSummaryService
    ) {}

    /**
     * GET /api/dashboard/summary
     * Query: scope_type=crop_cycle|project|year, scope_id=... (optional)
     * Returns one payload with all metrics for all role views. Tenant-scoped.
     */
    public function summary(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant context required'], 400);
        }

        $validator = Validator::make($request->all(), [
            'scope_type' => 'nullable|string|in:crop_cycle,project,year',
            'scope_id' => 'nullable|string|uuid',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $params = array_filter([
            'scope_type' => $request->input('scope_type'),
            'scope_id' => $request->input('scope_id'),
            'year' => $request->input('year'),
        ]);

        $data = $this->dashboardSummaryService->getSummary($tenantId, $params);

        return response()->json($data);
    }
}
