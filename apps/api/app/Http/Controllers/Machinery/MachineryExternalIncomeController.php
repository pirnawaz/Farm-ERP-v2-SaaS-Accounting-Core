<?php

namespace App\Http\Controllers\Machinery;

use App\Http\Controllers\Controller;
use App\Models\CropCycle;
use App\Models\Machine;
use App\Models\Party;
use App\Services\Machinery\MachineryExternalIncomePostingService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MachineryExternalIncomeController extends Controller
{
    public function __construct(
        private MachineryExternalIncomePostingService $postingService
    ) {}

    /**
     * POST /api/v1/machinery/external-income
     * External / third-party machinery work: Dr AR, Cr machinery income (machine-attributed).
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'machine_id' => ['required', 'uuid', 'exists:machines,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);

        Machine::where('id', $validated['machine_id'])->where('tenant_id', $tenantId)->firstOrFail();
        CropCycle::where('id', $validated['crop_cycle_id'])->where('tenant_id', $tenantId)->firstOrFail();
        Party::where('id', $validated['party_id'])->where('tenant_id', $tenantId)->firstOrFail();

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');

        $pg = $this->postingService->post(
            $tenantId,
            $validated['machine_id'],
            $validated['crop_cycle_id'],
            (float) $validated['amount'],
            $validated['posting_date'],
            [
                'party_id' => $validated['party_id'],
                'memo' => $validated['memo'] ?? null,
            ],
            $idempotencyKey
        );

        return response()->json(['posting_group' => $pg], 201);
    }
}
