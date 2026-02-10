<?php

namespace App\Http\Controllers;

use App\Models\PostingGroup;
use App\Services\ReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostingGroupController extends Controller
{
    public function __construct(
        private readonly ReversalService $reversalService
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $postingGroup = PostingGroup::where('tenant_id', $tenantId)
            ->with(['allocationRows', 'ledgerEntries.account'])
            ->where('id', $id)
            ->firstOrFail();
        
        return response()->json($postingGroup);
    }

    public function ledgerEntries(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $postingGroup = PostingGroup::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        $ledgerEntries = $postingGroup->ledgerEntries()
            ->with('account')
            ->get();
        
        return response()->json($ledgerEntries);
    }

    public function allocationRows(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $postingGroup = PostingGroup::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        $allocationRows = $postingGroup->allocationRows()->get();
        
        return response()->json($allocationRows);
    }

    public function reverse(Request $request, string $id): JsonResponse
    {
        $this->authorizeReversal($request);
        
        $tenantId = $request->attributes->get('tenant_id');
        
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'posting_date' => 'required|date',
            'reason' => 'required|string|max:1000',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            $reversalPostingGroup = $this->reversalService->reversePostingGroup(
                $id,
                $tenantId,
                $request->input('posting_date'),
                $request->input('reason')
            );
            
            // Log audit event
            $this->logAudit($request, 'PostingGroup', $id, 'REVERSE', [
                'posting_date' => $request->input('posting_date'),
                'reason' => $request->input('reason'),
                'reversal_posting_group_id' => $reversalPostingGroup->id,
            ]);
            
            return response()->json($reversalPostingGroup, 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Posting group not found'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (\Exception $e) {
            // DB exceptions (closed cycle, date range, etc.) will surface here
            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }

    public function reversals(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $postingGroup = PostingGroup::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        $reversals = $postingGroup->reversals()
            ->with(['allocationRows', 'ledgerEntries.account'])
            ->get();
        
        return response()->json($reversals);
    }
}
