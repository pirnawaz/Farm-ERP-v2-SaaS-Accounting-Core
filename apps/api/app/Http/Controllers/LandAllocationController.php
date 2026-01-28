<?php

namespace App\Http\Controllers;

use App\Models\LandAllocation;
use App\Services\TenantContext;
use App\Services\LandAllocationService;
use Illuminate\Http\Request;

class LandAllocationController extends Controller
{
    public function __construct(
        private LandAllocationService $allocationService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = LandAllocation::where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'party']);

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        if ($request->has('land_parcel_id')) {
            $query->where('land_parcel_id', $request->land_parcel_id);
        }

        $allocations = $query->orderBy('created_at', 'desc')->get();

        return response()->json($allocations);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'land_parcel_id' => ['required', 'uuid', 'exists:land_parcels,id'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'allocation_mode' => ['nullable', 'string', 'in:OWNER,HARI'],
            'allocated_acres' => ['required', 'numeric', 'min:0.01'],
        ]);

        // Determine allocation mode and party_id
        $allocationMode = $request->allocation_mode;
        $partyId = $request->party_id;

        // If allocation_mode is provided, enforce it
        if ($allocationMode === 'HARI') {
            if (!$partyId) {
                return response()->json(['errors' => ['party_id' => ['Party ID is required when allocation_mode is HARI']]], 422);
            }
        } elseif ($allocationMode === 'OWNER') {
            $partyId = null; // Force null for OWNER mode
        } else {
            // Infer mode from party_id presence
            if ($partyId) {
                $allocationMode = 'HARI';
            } else {
                $allocationMode = 'OWNER';
            }
        }

        // Validate acre allocation with locking
        $this->allocationService->validateAcreAllocation(
            $tenantId,
            $request->land_parcel_id,
            $request->crop_cycle_id,
            $request->allocated_acres
        );

        $allocation = LandAllocation::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $request->crop_cycle_id,
            'land_parcel_id' => $request->land_parcel_id,
            'party_id' => $partyId,
            'allocated_acres' => $request->allocated_acres,
        ]);

        return response()->json($allocation->load(['cropCycle', 'landParcel', 'party']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $allocation = LandAllocation::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'party'])
            ->firstOrFail();

        return response()->json($allocation);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $allocation = LandAllocation::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'crop_cycle_id' => ['sometimes', 'required', 'uuid', 'exists:crop_cycles,id'],
            'land_parcel_id' => ['sometimes', 'required', 'uuid', 'exists:land_parcels,id'],
            'party_id' => ['sometimes', 'nullable', 'uuid', 'exists:parties,id'],
            'allocation_mode' => ['sometimes', 'nullable', 'string', 'in:OWNER,HARI'],
            'allocated_acres' => ['sometimes', 'required', 'numeric', 'min:0.01'],
        ]);

        // Determine allocation mode and party_id
        $allocationMode = $request->allocation_mode;
        $partyId = $request->has('party_id') ? $request->party_id : $allocation->party_id;

        // If allocation_mode is provided, enforce it
        if ($allocationMode === 'HARI') {
            if (!$partyId) {
                return response()->json(['errors' => ['party_id' => ['Party ID is required when allocation_mode is HARI']]], 422);
            }
        } elseif ($allocationMode === 'OWNER') {
            $partyId = null; // Force null for OWNER mode
        } elseif ($request->has('party_id')) {
            // Infer mode from party_id presence if mode not provided
            if ($partyId) {
                $allocationMode = 'HARI';
            } else {
                $allocationMode = 'OWNER';
            }
        }

        $landParcelId = $request->land_parcel_id ?? $allocation->land_parcel_id;
        $cropCycleId = $request->crop_cycle_id ?? $allocation->crop_cycle_id;
        $newAcres = $request->allocated_acres ?? $allocation->allocated_acres;

        // Validate acre allocation with locking (excluding current allocation)
        if ($request->has('allocated_acres') || $request->has('land_parcel_id') || $request->has('crop_cycle_id')) {
            $this->allocationService->validateAcreAllocation(
                $tenantId,
                $landParcelId,
                $cropCycleId,
                $newAcres,
                $allocation->id
            );
        }

        $updateData = $request->only(['crop_cycle_id', 'land_parcel_id', 'allocated_acres']);
        if ($request->has('party_id') || $request->has('allocation_mode')) {
            $updateData['party_id'] = $partyId;
        }

        $allocation->update($updateData);

        return response()->json($allocation->load(['cropCycle', 'landParcel', 'party']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $allocation = LandAllocation::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $allocation->delete();

        return response()->json(null, 204);
    }
}
