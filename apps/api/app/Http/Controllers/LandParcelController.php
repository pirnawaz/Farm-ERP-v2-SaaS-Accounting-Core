<?php

namespace App\Http\Controllers;

use App\Models\LandParcel;
use App\Models\LandDocument;
use App\Models\LandAllocation;
use App\Http\Requests\StoreLandParcelRequest;
use App\Services\TenantContext;
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
            ->with(['cropCycle', 'party'])
            ->get()
            ->groupBy('crop_cycle_id')
            ->map(function ($allocs) {
                $cycle = $allocs->first()->cropCycle;
                $totalAllocated = $allocs->sum('allocated_acres');
                return [
                    'crop_cycle' => $cycle,
                    'total_allocated_acres' => $totalAllocated,
                    'allocations' => $allocs,
                ];
            });

        $remainingAcres = $parcel->total_acres - $parcel->landAllocations()->sum('allocated_acres');

        $parcelData = $parcel->toArray();
        $parcelData['allocation_summary'] = $allocationsByCycle->values();
        $parcelData['remaining_acres'] = $remainingAcres;

        return response()->json($parcelData);
    }

    public function update(StoreLandParcelRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $parcel->update($request->validated());

        return response()->json($parcel);
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $parcel = LandParcel::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

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
}
