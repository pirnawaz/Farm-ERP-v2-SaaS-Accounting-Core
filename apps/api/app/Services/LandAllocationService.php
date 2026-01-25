<?php

namespace App\Services;

use App\Models\LandAllocation;
use App\Models\LandParcel;
use Illuminate\Support\Facades\DB;

class LandAllocationService
{
    /**
     * Validate that the sum of allocated acres for a land parcel and crop cycle
     * does not exceed the total acres of the land parcel.
     * Uses row-level locking to prevent race conditions.
     * 
     * @param string $tenantId
     * @param string $landParcelId
     * @param string $cropCycleId
     * @param float $newAllocatedAcres
     * @param string|null $excludeAllocationId Exclude this allocation from the sum (for updates)
     * @throws \Exception if validation fails
     */
    public function validateAcreAllocation(
        string $tenantId,
        string $landParcelId,
        string $cropCycleId,
        float $newAllocatedAcres,
        ?string $excludeAllocationId = null
    ): void {
        DB::transaction(function () use ($tenantId, $landParcelId, $cropCycleId, $newAllocatedAcres, $excludeAllocationId) {
            // Lock the land parcel row for update
            $landParcel = LandParcel::where('id', $landParcelId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            // Calculate current sum of allocated acres for this parcel and crop cycle
            $query = LandAllocation::where('tenant_id', $tenantId)
                ->where('land_parcel_id', $landParcelId)
                ->where('crop_cycle_id', $cropCycleId);

            if ($excludeAllocationId) {
                $query->where('id', '!=', $excludeAllocationId);
            }

            $currentSum = $query->sum('allocated_acres') ?? 0;
            $totalAfterNew = $currentSum + $newAllocatedAcres;

            if ($totalAfterNew > $landParcel->total_acres) {
                throw new \Exception(
                    "Total allocated acres ({$totalAfterNew}) would exceed land parcel total acres ({$landParcel->total_acres})"
                );
            }
        });
    }

    /**
     * Get remaining unallocated acres for a land parcel in a crop cycle.
     * 
     * @param string $tenantId
     * @param string $landParcelId
     * @param string $cropCycleId
     * @return float
     */
    public function getRemainingAcres(string $tenantId, string $landParcelId, string $cropCycleId): float
    {
        $landParcel = LandParcel::where('id', $landParcelId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $allocated = LandAllocation::where('tenant_id', $tenantId)
            ->where('land_parcel_id', $landParcelId)
            ->where('crop_cycle_id', $cropCycleId)
            ->sum('allocated_acres') ?? 0;

        return max(0, $landParcel->total_acres - $allocated);
    }
}
