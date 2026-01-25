<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Http\Requests\PostSettlementRequest;
use App\Services\TenantContext;
use App\Services\SettlementService;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function __construct(
        private SettlementService $settlementService
    ) {}

    public function preview(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $upToDate = $request->input('up_to_date');

        $preview = $this->settlementService->previewSettlement($id, $tenantId, $upToDate);

        return response()->json($preview);
    }

    public function offsetPreview(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $postingDate = $request->input('posting_date');
        if (!$postingDate) {
            return response()->json(['error' => 'posting_date is required'], 400);
        }

        $preview = $this->settlementService->offsetPreview($id, $tenantId, $postingDate);

        return response()->json($preview);
    }

    public function post(PostSettlementRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $applyAdvanceOffset = $request->boolean('apply_advance_offset', false);
        $advanceOffsetAmount = $applyAdvanceOffset ? (float) $request->input('advance_offset_amount') : null;

        $result = $this->settlementService->postSettlement(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key,
            $request->up_to_date,
            $applyAdvanceOffset,
            $advanceOffsetAmount
        );

        return response()->json($result, 201);
    }
}
