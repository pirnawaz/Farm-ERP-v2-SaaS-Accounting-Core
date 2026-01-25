<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\LandAllocation;
use App\Models\CropCycle;
use App\Models\Party;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = Project::where('tenant_id', $tenantId)
            ->with(['cropCycle', 'party', 'landAllocation']);

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        $projects = $query->orderBy('name')->get();
        
        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'land_allocation_id' => ['nullable', 'uuid', 'exists:land_allocations,id'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,CLOSED'],
        ]);

        // Verify party and crop cycle belong to tenant
        Party::where('id', $request->party_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        CropCycle::where('id', $request->crop_cycle_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $project = Project::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'party_id' => $request->party_id,
            'crop_cycle_id' => $request->crop_cycle_id,
            'land_allocation_id' => $request->land_allocation_id,
            'status' => $request->status ?? 'ACTIVE',
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'party', 'landAllocation', 'projectRule'])
            ->firstOrFail();

        return response()->json($project);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'crop_cycle_id' => ['sometimes', 'required', 'uuid', 'exists:crop_cycles,id'],
            'land_allocation_id' => ['sometimes', 'nullable', 'uuid', 'exists:land_allocations,id'],
            'status' => ['sometimes', 'string', 'in:ACTIVE,CLOSED'],
        ]);

        $project->update($request->only(['name', 'party_id', 'crop_cycle_id', 'land_allocation_id', 'status']));

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $project->delete();

        return response()->json(null, 204);
    }

    public function fromAllocation(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'land_allocation_id' => ['required', 'uuid', 'exists:land_allocations,id'],
        ]);

        $allocation = LandAllocation::where('id', $request->land_allocation_id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'party'])
            ->firstOrFail();

        // Build project name
        $projectName = sprintf(
            '%s – %s – %s – %s acres',
            $allocation->cropCycle->name,
            $allocation->landParcel->name,
            $allocation->party->name,
            $allocation->allocated_acres
        );

        $project = Project::create([
            'tenant_id' => $tenantId,
            'name' => $projectName,
            'party_id' => $allocation->party_id,
            'crop_cycle_id' => $allocation->crop_cycle_id,
            'land_allocation_id' => $allocation->id,
            'status' => 'ACTIVE',
        ]);

        return response()->json($project->load(['cropCycle', 'party', 'landAllocation']), 201);
    }
}
