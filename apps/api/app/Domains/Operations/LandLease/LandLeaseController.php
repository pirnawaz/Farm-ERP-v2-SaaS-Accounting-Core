<?php

namespace App\Domains\Operations\LandLease;

use App\Http\Controllers\Controller;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class LandLeaseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', LandLease::class);

        $tenantId = TenantContext::getTenantId($request);
        $leases = LandLease::where('tenant_id', $tenantId)
            ->with(['project:id,name', 'landParcel:id,name', 'landlordParty:id,name'])
            ->orderBy('start_date', 'desc')
            ->get();

        return response()->json($leases);
    }

    public function store(StoreLandLeaseRequest $request)
    {
        $this->authorize('create', LandLease::class);

        $tenantId = TenantContext::getTenantId($request);
        $lease = LandLease::create([
            'tenant_id' => $tenantId,
            'project_id' => $request->project_id,
            'land_parcel_id' => $request->land_parcel_id,
            'landlord_party_id' => $request->landlord_party_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'rent_amount' => $request->rent_amount,
            'frequency' => $request->frequency,
            'notes' => $request->notes,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json($lease->load(['project:id,name', 'landParcel:id,name', 'landlordParty:id,name']), 201);
    }

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $lease = LandLease::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['project', 'landParcel', 'landlordParty'])
            ->firstOrFail();

        $this->authorize('view', $lease);

        return response()->json($lease);
    }

    public function update(UpdateLandLeaseRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $lease = LandLease::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('update', $lease);

        $lease->update($request->validated());

        return response()->json($lease->fresh(['project:id,name', 'landParcel:id,name', 'landlordParty:id,name']));
    }

    public function destroy(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);
        $lease = LandLease::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $this->authorize('delete', $lease);

        $lease->delete();

        return response()->json(null, 204);
    }
}
