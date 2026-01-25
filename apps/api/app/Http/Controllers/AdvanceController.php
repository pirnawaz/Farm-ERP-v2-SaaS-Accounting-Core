<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\Party;
use App\Models\Project;
use App\Http\Requests\StoreAdvanceRequest;
use App\Http\Requests\UpdateAdvanceRequest;
use App\Http\Requests\PostAdvanceRequest;
use App\Services\TenantContext;
use App\Services\AdvanceService;
use Illuminate\Http\Request;

class AdvanceController extends Controller
{
    public function __construct(
        private AdvanceService $advanceService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Advance::where('tenant_id', $tenantId)
            ->with(['party', 'project', 'cropCycle', 'postingGroup']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->has('party_id')) {
            $query->where('party_id', $request->party_id);
        }

        if ($request->has('date_from')) {
            $query->where('posting_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('posting_date', '<=', $request->date_to);
        }

        $advances = $query->orderBy('posting_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($advances);
    }

    public function store(StoreAdvanceRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        // Verify party belongs to tenant
        Party::where('id', $request->party_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Verify project belongs to tenant if provided
        if ($request->project_id) {
            Project::where('id', $request->project_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $advance = Advance::create([
            'tenant_id' => $tenantId,
            'party_id' => $request->party_id,
            'type' => $request->type,
            'direction' => $request->direction,
            'amount' => $request->amount,
            'posting_date' => $request->posting_date,
            'method' => $request->method,
            'project_id' => $request->project_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'notes' => $request->notes,
            'status' => 'DRAFT',
        ]);

        return response()->json($advance->load(['party', 'project', 'cropCycle']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $advance = Advance::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['party', 'project', 'cropCycle', 'postingGroup'])
            ->firstOrFail();

        return response()->json($advance);
    }

    public function update(UpdateAdvanceRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $advance = Advance::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        // Verify party belongs to tenant if changed
        if ($request->has('party_id')) {
            Party::where('id', $request->party_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        // Verify project belongs to tenant if changed
        if ($request->has('project_id') && $request->project_id) {
            Project::where('id', $request->project_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();
        }

        $advance->update($request->validated());

        return response()->json($advance->load(['party', 'project', 'cropCycle']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $advance = Advance::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'DRAFT')
            ->firstOrFail();

        $advance->delete();

        return response()->json(null, 204);
    }

    public function post(PostAdvanceRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $userRole = $request->attributes->get('user_role');

        $postingGroup = $this->advanceService->postAdvance(
            $id,
            $tenantId,
            $request->posting_date,
            $request->idempotency_key,
            $request->crop_cycle_id,
            $userRole
        );

        return response()->json($postingGroup, 201);
    }
}
