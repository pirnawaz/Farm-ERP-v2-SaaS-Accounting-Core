<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Settlement;
use App\Http\Requests\PostSettlementRequest;
use App\Services\TenantContext;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class SettlementController extends Controller
{
    public function __construct(
        private SettlementService $settlementService
    ) {}

    // Existing project-based settlement methods (for backward compatibility)
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
        $this->authorizePosting($request);
        
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

        // Log audit event
        $this->logAudit($request, 'Settlement', $id, 'POST', [
            'posting_date' => $request->posting_date,
            'posting_group_id' => $result['posting_group']['id'] ?? null,
        ]);

        return response()->json($result, 201);
    }

    // New sales-based settlement methods (Phase 11)
    public function previewSettlement(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'share_rule_id' => ['nullable', 'uuid', 'exists:share_rules,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $preview = $this->settlementService->preview([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $request->input('crop_cycle_id'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
                'share_rule_id' => $request->input('share_rule_id'),
            ]);

            return response()->json($preview);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $query = Settlement::where('tenant_id', $tenantId)
            ->with(['shareRule', 'cropCycle', 'lines.party']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        if ($request->has('share_rule_id')) {
            $query->where('share_rule_id', $request->share_rule_id);
        }

        $settlements = $query->orderBy('created_at', 'desc')->get();

        return response()->json($settlements);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'sale_ids' => ['required', 'array', 'min:1'],
            'sale_ids.*' => ['required', 'uuid', 'exists:sales,id'],
            'share_rule_id' => ['required', 'uuid', 'exists:share_rules,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'settlement_no' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $data = $request->all();
            $data['tenant_id'] = $tenantId;
            $data['created_by'] = $request->user()->id ?? null;

            $settlement = $this->settlementService->create($data);
            return response()->json($settlement, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);

        $settlement = Settlement::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['shareRule', 'cropCycle', 'lines.party', 'sales', 'postingGroup', 'reversalPostingGroup'])
            ->firstOrFail();

        return response()->json($settlement);
    }

    public function postSettlement(Request $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'posting_date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settlement = Settlement::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $result = $this->settlementService->post($settlement, $request->posting_date);
            
            // Log audit event
            $this->logAudit($request, 'Settlement', $id, 'POST', [
                'posting_date' => $request->posting_date,
                'posting_group_id' => $result['posting_group']['id'] ?? null,
            ]);
            
            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $this->authorizeReversal($request);
        
        $tenantId = TenantContext::getTenantId($request);

        $validator = Validator::make($request->all(), [
            'reversal_date' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $settlement = Settlement::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $result = $this->settlementService->reverse($settlement, $request->reversal_date);
            
            // Log audit event
            $this->logAudit($request, 'Settlement', $id, 'REVERSE', [
                'reversal_date' => $request->reversal_date,
            ]);
            
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/settlements/crop-cycles/{id}/preview
     * Preview crop cycle settlement (ledger-based).
     */
    public function cropCyclePreview(Request $request, string $id): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        $upToDate = $request->input('up_to_date', now()->format('Y-m-d'));
        $validator = Validator::make(['up_to_date' => $upToDate], ['up_to_date' => ['required', 'date']]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        try {
            $preview = $this->settlementService->previewCropCycleSettlement($id, $tenantId, $upToDate);
            return response()->json($preview);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST /api/settlements/crop-cycles/{id}/post
     * Post one settlement for the crop cycle (requires posting_date and idempotency_key).
     */
    public function cropCyclePost(Request $request, string $id): JsonResponse
    {
        $this->authorizePosting($request);
        $tenantId = TenantContext::getTenantId($request);
        $validator = Validator::make($request->all(), [
            'posting_date' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        CropCycle::where('id', $id)->where('tenant_id', $tenantId)->firstOrFail();
        try {
            $result = $this->settlementService->settleCropCycle(
                $id,
                $tenantId,
                $request->posting_date,
                $request->idempotency_key
            );
            $this->logAudit($request, 'CropCycleSettlement', $id, 'POST', [
                'posting_date' => $request->posting_date,
                'posting_group_id' => $result['posting_group']->id ?? null,
            ]);
            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
