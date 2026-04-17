<?php

namespace App\Http\Controllers;

use App\Models\LandParcel;
use App\Models\LandDocument;
use App\Models\LandAllocation;
use App\Models\AgreementAllocation;
use App\Models\LandParcelAuditLog;
use App\Models\CropCycle;
use App\Http\Requests\StoreLandParcelRequest;
use App\Http\Requests\UpdateLandParcelRequest;
use App\Services\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LandParcelController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = TenantContext::getTenantId($request);
        
        $parcels = LandParcel::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json($parcels);
    }

    public function store(StoreLandParcelRequest $request)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'total_acres' => $request->total_acres,
            'notes' => $request->notes,
        ]);

        return response()->json($parcel, 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['landAllocations.cropCycle', 'landAllocations.party'])
            ->firstOrFail();

        // Add allocation summary grouped by crop_cycle
        $allocationsByCycle = $parcel->landAllocations()
            ->with(['cropCycle.tenantCropItem.cropCatalogItem', 'party'])
            ->get()
            ->groupBy('crop_cycle_id')
            ->map(function ($allocs) {
                $cycle = $allocs->first()->cropCycle;
                $item = $cycle?->tenantCropItem;
                $cropDisplayName = $item
                    ? ($item->display_name !== null && $item->display_name !== ''
                        ? $item->display_name
                        : ($item->cropCatalogItem?->default_name ?? $item->custom_name))
                    : ($cycle?->crop_type ?? null);
                $cycleArr = $cycle ? $cycle->toArray() : null;
                if ($cycleArr !== null) {
                    $cycleArr['crop_display_name'] = $cropDisplayName;
                }
                $totalAllocated = $allocs->sum('allocated_acres');
                return [
                    'crop_cycle' => $cycleArr,
                    'total_allocated_acres' => $totalAllocated,
                    'allocations' => $allocs,
                ];
            });

        $remainingAcres = $parcel->total_acres - $parcel->landAllocations()->sum('allocated_acres');

        $asOf = Carbon::today();
        $agreementAllocations = AgreementAllocation::query()
            ->where('tenant_id', $tenantId)
            ->where('land_parcel_id', $parcel->id)
            ->with(['agreement.party', 'legacyField'])
            ->orderBy('starts_on', 'desc')
            ->orderBy('id')
            ->get();

        $activeAgreementAllocated = (float) $agreementAllocations
            ->filter(fn (AgreementAllocation $a) => $a->isActiveOn($asOf))
            ->sum('allocated_area');

        $parcelData = $parcel->toArray();
        $parcelData['allocation_summary'] = $allocationsByCycle->values();
        $parcelData['remaining_acres'] = $remainingAcres;
        $parcelData['agreement_allocations'] = $agreementAllocations;
        $parcelData['agreement_allocation_summary'] = [
            'as_of' => $asOf->format('Y-m-d'),
            'active_allocated_area' => $activeAgreementAllocated,
            'available_area_after_agreement_allocations' => max(0, (float) $parcel->total_acres - $activeAgreementAllocated),
            'parcel_total_area' => (float) $parcel->total_acres,
        ];

        return response()->json($parcelData);
    }

    public function update(UpdateLandParcelRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $userId = $request->attributes->get('user_id');
        $userRole = $request->attributes->get('user_role');

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($request->has('total_acres')) {
            $allocatedAcres = (float) LandAllocation::where('land_parcel_id', $parcel->id)->sum('allocated_acres');
            $newTotalAcres = (float) $request->input('total_acres');
            if ($newTotalAcres < $allocatedAcres) {
                return response()->json([
                    'message' => "Total acres cannot be less than allocated acres (allocated: {$allocatedAcres}).",
                ], 422);
            }
        }

        $validated = $request->validated();
        $trackedFields = ['name', 'total_acres', 'notes'];
        $oldValues = [
            'name' => $parcel->name,
            'total_acres' => $parcel->total_acres !== null ? (string) $parcel->total_acres : null,
            'notes' => $parcel->notes,
        ];

        $parcel->update($validated);

        foreach ($trackedFields as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }
            $newVal = $validated[$field];
            $newValue = $newVal !== null && $newVal !== '' ? (string) $newVal : null;
            $oldValue = $oldValues[$field] ?? null;
            if ((string) $oldValue !== (string) $newValue) {
                LandParcelAuditLog::create([
                    'tenant_id' => $tenantId,
                    'land_parcel_id' => $parcel->id,
                    'changed_by_user_id' => $userId,
                    'changed_by_role' => $userRole,
                    'field_name' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'changed_at' => now(),
                    'request_id' => $request->header('X-Request-ID'),
                    'source' => null,
                ]);
            }
        }

        return response()->json($parcel->fresh());
    }

    public function audit(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $limit = min((int) $request->input('limit', 50), 100);
        $logs = LandParcelAuditLog::where('land_parcel_id', $parcel->id)
            ->where('tenant_id', $tenantId)
            ->orderBy('changed_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($logs);
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $allocationCount = LandAllocation::where('land_parcel_id', $parcel->id)->count();
        if ($allocationCount > 0) {
            return response()->json([
                'message' => 'Cannot delete a land parcel that has allocations. Remove or reassign allocations first.',
            ], 422);
        }

        $parcel->delete();

        return response()->json(null, 204);
    }

    public function storeDocument(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $request->validate([
            'file_path' => ['required', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $document = LandDocument::create([
            'land_parcel_id' => $parcel->id,
            'file_path' => $request->file_path,
            'description' => $request->description,
        ]);

        return response()->json($document, 201);
    }

    public function listDocuments(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $documents = LandDocument::where('land_parcel_id', $parcel->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * GET /api/land-parcels/{id}/rotation-warnings?crop_cycle_id=...
     * Returns rotation warnings when allocating this parcel to the given crop cycle (same crop/category as prior cycle).
     */
    public function rotationWarnings(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $request->validate([
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
        ]);
        $cropCycleId = $request->query('crop_cycle_id');

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $currentCycle = CropCycle::where('id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->with('tenantCropItem.cropCatalogItem')
            ->firstOrFail();

        $warnings = [];

        if (!$currentCycle->tenantCropItem || !$currentCycle->tenantCropItem->cropCatalogItem) {
            return response()->json(['warnings' => []]);
        }

        $currentCode = $currentCycle->tenantCropItem->cropCatalogItem->code;
        $currentCategory = $currentCycle->tenantCropItem->cropCatalogItem->category;

        $currentStartDate = $currentCycle->start_date;
        $priorAllocation = LandAllocation::where('land_allocations.land_parcel_id', $parcel->id)
            ->where('land_allocations.tenant_id', $tenantId)
            ->where('land_allocations.crop_cycle_id', '!=', $cropCycleId)
            ->join('crop_cycles', 'land_allocations.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('crop_cycles.start_date', '<', $currentStartDate)
            ->orderBy('crop_cycles.start_date', 'desc')
            ->select('land_allocations.*')
            ->first();

        if (!$priorAllocation) {
            return response()->json(['warnings' => []]);
        }

        $priorCycle = CropCycle::where('id', $priorAllocation->crop_cycle_id)
            ->with('tenantCropItem.cropCatalogItem')
            ->first();
        if (!$priorCycle?->tenantCropItem?->cropCatalogItem) {
            return response()->json(['warnings' => []]);
        }

        $priorCode = $priorCycle->tenantCropItem->cropCatalogItem->code;
        $priorCategory = $priorCycle->tenantCropItem->cropCatalogItem->category;

        if ($currentCode === $priorCode) {
            $warnings[] = [
                'code' => 'SAME_CROP_CONSECUTIVE',
                'message' => "This parcel was allocated to the same crop ({$priorCycle->tenantCropItem->cropCatalogItem->default_name}) in the previous cycle. Consider crop rotation.",
                'severity' => 'warning',
            ];
        }
        if ($currentCategory === $priorCategory && $currentCode !== $priorCode) {
            $warnings[] = [
                'code' => 'SAME_CATEGORY_CONSECUTIVE',
                'message' => "This parcel was in the same crop category ({$priorCategory}) in the previous cycle. Consider rotating to a different category.",
                'severity' => 'warning',
            ];
        }

        return response()->json(['warnings' => $warnings]);
    }
}
