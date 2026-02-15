<?php

namespace App\Http\Controllers;

use App\Domains\Operations\LandLease\LandLease;
use App\Domains\Operations\LandLease\LandLeaseAccrual;
use App\Domains\Operations\LandLease\LandLeaseAccrualPostingService;
use App\Http\Requests\PostLandLeaseAccrualRequest;
use App\Http\Requests\ReverseLandLeaseAccrualRequest;
use App\Http\Requests\StoreLandLeaseAccrualRequest;
use App\Http\Requests\UpdateLandLeaseAccrualRequest;
use App\Services\ReversalService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class LandLeaseAccrualController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LandLeaseAccrual::class);

        $tenantId = TenantContext::getTenantId($request);
        $query = LandLeaseAccrual::where('tenant_id', $tenantId)
            ->with(['lease:id,project_id', 'project:id,name']);

        if ($request->filled('lease_id')) {
            $query->where('lease_id', $request->lease_id);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $query->orderBy('period_start', 'desc');

        $perPage = max(1, min(100, (int) $request->get('per_page', 15)));
        $accruals = $query->paginate($perPage);

        return response()->json($accruals);
    }

    public function store(StoreLandLeaseAccrualRequest $request)
    {
        $this->authorize('create', LandLeaseAccrual::class);

        $tenantId = TenantContext::getTenantId($request);
        $lease = LandLease::where('id', $request->lease_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($lease->project_id !== $request->project_id) {
            return response()->json(['message' => 'Project must match the lease\'s project.'], 422);
        }

        $accrual = LandLeaseAccrual::create([
            'tenant_id' => $tenantId,
            'lease_id' => $request->lease_id,
            'project_id' => $request->project_id,
            'period_start' => $request->period_start,
            'period_end' => $request->period_end,
            'amount' => $request->amount,
            'memo' => $request->memo,
            'status' => LandLeaseAccrual::STATUS_DRAFT,
        ]);

        return response()->json($accrual->load(['lease:id,project_id', 'project:id,name']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $accrual = LandLeaseAccrual::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['lease', 'project'])
            ->firstOrFail();

        $this->authorize('view', $accrual);

        return response()->json($accrual);
    }

    public function update(UpdateLandLeaseAccrualRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $accrual = LandLeaseAccrual::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('update', $accrual);

        $accrual->update($request->validated());

        return response()->json($accrual->fresh(['lease:id,project_id', 'project:id,name']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $accrual = LandLeaseAccrual::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('delete', $accrual);

        $accrual->delete();

        return response()->json(null, 204);
    }

    public function post(PostLandLeaseAccrualRequest $request, string $id, LandLeaseAccrualPostingService $postingService)
    {
        $tenantId = TenantContext::getTenantId($request);
        $accrual = LandLeaseAccrual::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('post', $accrual);

        if ($accrual->status !== LandLeaseAccrual::STATUS_DRAFT) {
            return response()->json(
                ['message' => 'Only DRAFT accruals can be posted.'],
                422
            );
        }

        $postedBy = $request->user()?->id ?? $request->header('X-User-Id');
        if (!$postedBy) {
            return response()->json(['message' => 'User context required for posting.'], 401);
        }
        try {
            $postingGroup = $postingService->postAccrual(
                $accrual->id,
                $tenantId,
                $request->validated('posting_date'),
                (string) $postedBy
            );
        } catch (\App\Exceptions\CropCycleClosedException $e) {
            return response()->json(['message' => 'Crop cycle is closed; cannot post.'], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $accrual->refresh();
        $accrual->load(['lease:id,project_id', 'project:id,name']);

        return response()->json([
            'accrual' => $accrual,
            'posting_group_id' => $postingGroup->id,
            'posting_group' => $postingGroup->only(['id', 'posting_date', 'source_type', 'source_id']),
        ]);
    }

    public function reverse(ReverseLandLeaseAccrualRequest $request, string $id, ReversalService $reversalService)
    {
        $tenantId = TenantContext::getTenantId($request);
        $accrual = LandLeaseAccrual::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('reverse', $accrual);

        if ($accrual->reversal_posting_group_id !== null) {
            return response()->json(
                ['message' => 'This accrual has already been reversed.'],
                422
            );
        }

        $postedBy = $request->user()?->id ?? $request->header('X-User-Id');
        if (!$postedBy) {
            return response()->json(['message' => 'User context required for reversal.'], 401);
        }

        try {
            $reversalPostingGroup = $reversalService->reversePostingGroup(
                $accrual->posting_group_id,
                $tenantId,
                $request->validated('posting_date'),
                $request->validated('reason') ?? 'Land lease accrual reversal'
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $accrual->update([
            'reversal_posting_group_id' => $reversalPostingGroup->id,
            'reversed_at' => now(),
            'reversed_by' => $postedBy,
            'reversal_reason' => $request->validated('reason'),
        ]);

        $accrual->refresh();
        $accrual->load(['lease:id,project_id', 'project:id,name']);

        return response()->json([
            'accrual' => $accrual,
            'reversal_posting_group_id' => $reversalPostingGroup->id,
            'reversal_posting_group' => $reversalPostingGroup->only(['id', 'posting_date', 'source_type', 'source_id']),
        ]);
    }
}
