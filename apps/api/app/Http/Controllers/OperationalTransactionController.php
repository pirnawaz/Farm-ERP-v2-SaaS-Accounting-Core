<?php

namespace App\Http\Controllers;

use App\Models\OperationalTransaction;
use App\Models\Project;
use App\Models\CropCycle;
use App\Http\Requests\PostOperationalTransactionRequest;
use App\Services\TenantContext;
use App\Services\PostingService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class OperationalTransactionController extends Controller
{
    public function __construct(
        private PostingService $postingService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = OperationalTransaction::where('tenant_id', $tenantId)
            ->with(['project', 'cropCycle']);

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->where('transaction_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('transaction_date', '<=', $request->date_to);
        }

        if ($request->has('classification')) {
            $query->where('classification', $request->classification);
        }

        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($transactions);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'type' => ['required', 'string', 'in:INCOME,EXPENSE'],
            'transaction_date' => ['required', 'date', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'classification' => ['required', 'string', 'in:SHARED,HARI_ONLY,FARM_OVERHEAD'],
        ]);

        // Validation rules per spec
        if ($request->classification === 'FARM_OVERHEAD') {
            if ($request->project_id) {
                return response()->json(['error' => 'FARM_OVERHEAD transactions cannot have project_id'], 422);
            }
            if (!$request->crop_cycle_id) {
                return response()->json(['error' => 'FARM_OVERHEAD transactions require crop_cycle_id'], 422);
            }
        } else {
            // SHARED or HARI_ONLY
            if (!$request->project_id) {
                return response()->json(['error' => 'SHARED and HARI_ONLY transactions require project_id'], 422);
            }
            if (!in_array($request->classification, ['SHARED', 'HARI_ONLY'])) {
                return response()->json(['error' => 'Invalid classification for project transaction'], 422);
            }
        }

        $project = null;
        if ($request->project_id) {
            $project = Project::where('id', $request->project_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $cropCycle = null;
        $cropCycleId = $request->crop_cycle_id ?? $project?->crop_cycle_id;
        if ($cropCycleId) {
            $cropCycle = CropCycle::where('id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        if ($cropCycle && $cropCycle->start_date && $cropCycle->end_date) {
            $txDate = Carbon::parse($request->transaction_date)->format('Y-m-d');
            if ($txDate < $cropCycle->start_date->format('Y-m-d')) {
                return response()->json(['error' => 'Transaction date is before crop cycle start date.'], 422);
            }
            if ($txDate > $cropCycle->end_date->format('Y-m-d')) {
                return response()->json(['error' => 'Transaction date is after crop cycle end date.'], 422);
            }
        }

        $transaction = OperationalTransaction::create([
            'tenant_id' => $tenantId,
            'project_id' => $request->project_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'type' => $request->type,
            'status' => 'DRAFT',
            'transaction_date' => $request->transaction_date,
            'amount' => $request->amount,
            'classification' => $request->classification,
            'created_by' => $request->attributes->get('user_id'), // Would come from auth in production
        ]);

        return response()->json($transaction->load(['project', 'cropCycle']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $transaction = OperationalTransaction::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['project', 'cropCycle'])
            ->firstOrFail();

        return response()->json($transaction);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $transaction = OperationalTransaction::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        $request->validate([
            'project_id' => ['sometimes', 'nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['sometimes', 'nullable', 'uuid', 'exists:crop_cycles,id'],
            'type' => ['sometimes', 'required', 'string', 'in:INCOME,EXPENSE'],
            'transaction_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'classification' => ['sometimes', 'required', 'string', 'in:SHARED,HARI_ONLY,FARM_OVERHEAD'],
        ]);

        $updates = $request->only(['project_id', 'crop_cycle_id', 'type', 'transaction_date', 'amount', 'classification']);
        $project = $transaction->project;
        if ($request->has('project_id')) {
            $project = $request->project_id
                ? Project::where('id', $request->project_id)->where('tenant_id', $tenantId)->first()
                : null;
        }
        $cropCycleId = $request->crop_cycle_id ?? $project?->crop_cycle_id ?? $transaction->crop_cycle_id;
        if ($cropCycleId && ($request->has('transaction_date') || $request->has('project_id') || $request->has('crop_cycle_id'))) {
            $cropCycle = CropCycle::where('id', $cropCycleId)->where('tenant_id', $tenantId)->first();
            $txDate = Carbon::parse($updates['transaction_date'] ?? $transaction->transaction_date)->format('Y-m-d');
            if ($cropCycle && $cropCycle->start_date && $cropCycle->end_date) {
                if ($txDate < $cropCycle->start_date->format('Y-m-d')) {
                    return response()->json(['error' => 'Transaction date is before crop cycle start date.'], 422);
                }
                if ($txDate > $cropCycle->end_date->format('Y-m-d')) {
                    return response()->json(['error' => 'Transaction date is after crop cycle end date.'], 422);
                }
            }
        }

        $transaction->update($updates);

        return response()->json($transaction->load(['project', 'cropCycle']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $transaction = OperationalTransaction::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        $transaction->delete();

        return response()->json(null, 204);
    }

    public function post(PostOperationalTransactionRequest $request, string $id)
    {
        $this->authorizePosting($request);
        
        $tenantId = TenantContext::getTenantId($request);

        $postingGroup = $this->postingService->postOperationalTransaction(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key
        );

        return response()->json($postingGroup, 201);
    }
}
