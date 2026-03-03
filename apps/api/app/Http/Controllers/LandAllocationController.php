<?php

namespace App\Http\Controllers;

use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\CropCycle;
use App\Services\TenantContext;
use App\Services\LandAllocationService;
use Illuminate\Http\Request;
use Throwable;

class LandAllocationController extends Controller
{
    public function __construct(
        private LandAllocationService $allocationService
    ) {}

    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $query = LandAllocation::where('tenant_id', $tenantId)
            ->with(['cropCycle', 'landParcel', 'party', 'projects']);

        if ($request->has('crop_cycle_id')) {
            $query->where('crop_cycle_id', $request->crop_cycle_id);
        }

        if ($request->has('land_parcel_id')) {
            $query->where('land_parcel_id', $request->land_parcel_id);
        }

        $allocations = $query->orderBy('created_at', 'desc')->get();

        $data = $allocations->map(fn (LandAllocation $a) => $this->formatAllocation($a));
        return response()->json($data);
    }

    public function store(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $validated = $request->validate([
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'land_parcel_id' => ['required', 'uuid', 'exists:land_parcels,id'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'allocation_mode' => ['nullable', 'string', 'in:OWNER,HARI'],
            'allocated_acres' => ['required', 'numeric', 'min:0.01'],
        ]);

        $parcel = LandParcel::where('id', $validated['land_parcel_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        $cropCycle = CropCycle::where('id', $validated['crop_cycle_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();
        if ($cropCycle->status !== 'OPEN') {
            return response()->json([
                'message' => 'Allocations can only be created or changed for open crop cycles.',
            ], 422);
        }
        if (!$cropCycle->tenant_crop_item_id) {
            return response()->json([
                'message' => 'The selected crop cycle must have a crop assigned before creating allocations.',
            ], 422);
        }

        // Determine allocation mode and party_id
        $allocationMode = $validated['allocation_mode'] ?? null;
        $partyId = $validated['party_id'] ?? null;

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

        $exists = LandAllocation::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $validated['crop_cycle_id'])
            ->where('land_parcel_id', $validated['land_parcel_id'])
            ->where(function ($q) use ($partyId) {
                if (empty($partyId)) {
                    $q->whereNull('party_id');
                } else {
                    $q->where('party_id', $partyId);
                }
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'An allocation already exists for this parcel, crop cycle and Hari.',
            ], 422);
        }

        try {
            $this->allocationService->validateAcreAllocation(
                $tenantId,
                $validated['land_parcel_id'],
                $validated['crop_cycle_id'],
                (float) $validated['allocated_acres']
            );
        } catch (Throwable $e) {
            $remaining = $this->allocationService->getRemainingAcres(
                $tenantId,
                $validated['land_parcel_id'],
                $validated['crop_cycle_id']
            );
            return response()->json([
                'message' => $e->getMessage(),
                'available_acres' => round($remaining, 2),
            ], 422);
        }

        $allocation = LandAllocation::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $validated['crop_cycle_id'],
            'land_parcel_id' => $validated['land_parcel_id'],
            'party_id' => $partyId,
            'allocated_acres' => $validated['allocated_acres'],
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

        $validated = $request->validate([
            'allocated_acres' => ['required', 'numeric', 'min:0.01'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
        ]);

        $partyId = $validated['party_id'] ?? null;

        // Duplicate guard: another allocation with same scope (excluding current)
        $exists = LandAllocation::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $allocation->crop_cycle_id)
            ->where('land_parcel_id', $allocation->land_parcel_id)
            ->where(function ($q) use ($partyId) {
                if (empty($partyId)) {
                    $q->whereNull('party_id');
                } else {
                    $q->where('party_id', $partyId);
                }
            })
            ->where('id', '!=', $allocation->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'An allocation already exists for this parcel, crop cycle and Hari.',
            ], 422);
        }

        $cropCycle = $allocation->cropCycle;
        if ($cropCycle && $cropCycle->status !== 'OPEN') {
            return response()->json([
                'message' => 'Allocations can only be created or changed for open crop cycles.',
            ], 422);
        }

        try {
            $this->allocationService->validateAcreAllocation(
                $tenantId,
                $allocation->land_parcel_id,
                $allocation->crop_cycle_id,
                (float) $validated['allocated_acres'],
                $allocation->id
            );
        } catch (Throwable $e) {
            $remaining = $this->allocationService->getRemainingAcres(
                $tenantId,
                $allocation->land_parcel_id,
                $allocation->crop_cycle_id
            );
            return response()->json([
                'message' => $e->getMessage(),
                'available_acres' => round($remaining, 2),
            ], 422);
        }

        $allocation->update([
            'allocated_acres' => $validated['allocated_acres'],
            'party_id' => $partyId,
        ]);

        $allocation->load(['cropCycle', 'landParcel', 'party', 'projects']);
        return response()->json($this->formatAllocation($allocation));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $allocation = LandAllocation::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($allocation->projects()->exists()) {
            return response()->json([
                'message' => 'Cannot delete allocation with a linked project.',
            ], 422);
        }

        $allocation->delete();
        return response()->noContent();
    }

    private function formatAllocation(LandAllocation $allocation): array
    {
        return [
            'id' => $allocation->id,
            'tenant_id' => $allocation->tenant_id,
            'crop_cycle_id' => $allocation->crop_cycle_id,
            'land_parcel_id' => $allocation->land_parcel_id,
            'party_id' => $allocation->party_id,
            'allocated_acres' => $allocation->allocated_acres,
            'created_at' => $allocation->created_at?->toIso8601String(),
            'allocation_mode' => $allocation->allocation_mode,
            'crop_cycle' => $allocation->cropCycle,
            'land_parcel' => $allocation->landParcel,
            'party' => $allocation->party ? [
                'id' => $allocation->party->id,
                'name' => $allocation->party->name,
            ] : null,
            'project' => $allocation->projects->first(),
        ];
    }
}
