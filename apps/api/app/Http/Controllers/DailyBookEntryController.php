<?php

namespace App\Http\Controllers;

use App\Models\DailyBookEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DailyBookEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $query = DailyBookEntry::where('tenant_id', $tenantId);
        
        if ($request->has('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }
        
        if ($request->has('from')) {
            $query->where('event_date', '>=', $request->input('from'));
        }
        
        if ($request->has('to')) {
            $query->where('event_date', '<=', $request->input('to'));
        }
        
        $entries = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json($entries);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $entry = DailyBookEntry::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        return response()->json($entry);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'project_id' => 'required|uuid|exists:projects,id',
            'type' => 'required|in:EXPENSE,INCOME',
            'event_date' => 'required|date',
            'description' => 'required|string|max:255',
            'gross_amount' => 'required|numeric|min:0',
            'currency_code' => 'sometimes|string|size:3',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // Verify project belongs to tenant
        $project = \App\Models\Project::where('id', $request->input('project_id'))
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        
        $entry = DailyBookEntry::create([
            'tenant_id' => $tenantId,
            'project_id' => $request->input('project_id'),
            'type' => $request->input('type'),
            'status' => 'DRAFT',
            'event_date' => $request->input('event_date'),
            'description' => $request->input('description'),
            'gross_amount' => $request->input('gross_amount'),
            'currency_code' => $request->input('currency_code', 'GBP'),
        ]);
        
        return response()->json($entry, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $entry = DailyBookEntry::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        // Block updates to POSTED entries
        if ($entry->status !== 'DRAFT') {
            return response()->json([
                'error' => 'Cannot update entry with status ' . $entry->status . '. Only DRAFT entries can be updated.'
            ], 409);
        }
        
        $validator = Validator::make($request->all(), [
            'project_id' => 'sometimes|uuid|exists:projects,id',
            'type' => 'sometimes|in:EXPENSE,INCOME',
            'event_date' => 'sometimes|date',
            'description' => 'sometimes|string|max:255',
            'gross_amount' => 'sometimes|numeric|min:0',
            'currency_code' => 'sometimes|string|size:3',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        // If project_id is being updated, verify it belongs to tenant
        if ($request->has('project_id')) {
            $project = \App\Models\Project::where('id', $request->input('project_id'))
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }
        
        $entry->update($request->only([
            'project_id',
            'type',
            'event_date',
            'description',
            'gross_amount',
            'currency_code',
        ]));
        
        return response()->json($entry);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $entry = DailyBookEntry::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
        
        // Block deletes to POSTED entries
        if ($entry->status !== 'DRAFT') {
            return response()->json([
                'error' => 'Cannot delete entry with status ' . $entry->status . '. Only DRAFT entries can be deleted.'
            ], 409);
        }
        
        $entry->delete();
        
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function post(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        
        $validator = Validator::make($request->all(), [
            'posting_date' => 'required|date',
        ]);
        
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        try {
            $postingService = new \App\Services\PostingService();
            $postingGroup = $postingService->postDailyBookEntry(
                $id,
                $tenantId,
                $request->input('posting_date')
            );
            
            return response()->json($postingGroup, 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Entry not found or not in DRAFT status'], 404);
        } catch (\Exception $e) {
            // DB exceptions (closed cycle, date range, etc.) will surface here
            return response()->json([
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
