<?php

namespace App\Http\Controllers;

use App\Models\LabWorkerBalance;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class LabourReportController extends Controller
{
    /**
     * GET /payables/outstanding — wages payable by worker for Payables page.
     * Returns: worker_id, worker_name, payable_balance, party_id (for Pay link when linked).
     */
    public function outstanding(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $rows = LabWorkerBalance::where('tenant_id', $tenantId)
            ->with('worker:id,name,party_id')
            ->get()
            ->map(function (LabWorkerBalance $b) {
                return [
                    'worker_id' => $b->worker_id,
                    'worker_name' => $b->worker?->name ?? '',
                    'payable_balance' => (string) $b->payable_balance,
                    'party_id' => $b->worker?->party_id,
                ];
            });

        return response()->json($rows);
    }
}
