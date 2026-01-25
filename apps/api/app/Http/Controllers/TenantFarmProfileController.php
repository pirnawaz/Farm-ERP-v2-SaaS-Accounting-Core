<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFarmProfileRequest;
use App\Models\Farm;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantFarmProfileController extends Controller
{
    /**
     * Get farm profile for the current tenant.
     * GET /api/tenant/farm-profile
     */
    public function show(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $farm = Farm::where('tenant_id', $tenantId)->firstOrFail();

        return response()->json([
            'id' => $farm->id,
            'tenant_id' => $farm->tenant_id,
            'farm_name' => $farm->farm_name,
            'country' => $farm->country,
            'address_line1' => $farm->address_line1,
            'address_line2' => $farm->address_line2,
            'city' => $farm->city,
            'region' => $farm->region,
            'postal_code' => $farm->postal_code,
            'phone' => $farm->phone,
            'created_at' => $farm->created_at->toIso8601String(),
            'updated_at' => $farm->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Update farm profile for the current tenant.
     * PUT /api/tenant/farm-profile
     */
    public function update(UpdateFarmProfileRequest $request): JsonResponse
    {
        $tenantId = TenantContext::getTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $farm = Farm::where('tenant_id', $tenantId)->firstOrFail();
        $farm->update($request->validated());

        return response()->json([
            'id' => $farm->id,
            'tenant_id' => $farm->tenant_id,
            'farm_name' => $farm->farm_name,
            'country' => $farm->country,
            'address_line1' => $farm->address_line1,
            'address_line2' => $farm->address_line2,
            'city' => $farm->city,
            'region' => $farm->region,
            'postal_code' => $farm->postal_code,
            'phone' => $farm->phone,
            'created_at' => $farm->created_at->toIso8601String(),
            'updated_at' => $farm->updated_at->toIso8601String(),
        ]);
    }
}
