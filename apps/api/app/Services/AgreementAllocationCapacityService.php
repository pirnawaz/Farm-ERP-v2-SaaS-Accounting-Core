<?php

namespace App\Services;

use App\Models\AgreementAllocation;
use App\Models\LandParcel;
use Carbon\Carbon;

/**
 * Enforces Rule A: overlapping active allocations on a parcel cannot exceed parcel total area.
 */
class AgreementAllocationCapacityService
{
    /**
     * @throws \InvalidArgumentException
     */
    public function assertWithinParcelCapacity(
        string $tenantId,
        string $landParcelId,
        string $allocatedArea,
        string $startsOn,
        ?string $endsOn,
        string $status,
        ?string $excludeAllocationId = null
    ): void {
        $parcel = LandParcel::where('id', $landParcelId)->where('tenant_id', $tenantId)->firstOrFail();
        $add = (float) $allocatedArea;
        if ($add <= 0) {
            throw new \InvalidArgumentException('allocated_area must be greater than zero.');
        }

        if ($status !== AgreementAllocation::STATUS_ACTIVE) {
            return;
        }

        $rangeStart = Carbon::parse($startsOn)->startOfDay();
        $rangeEnd = $endsOn !== null ? Carbon::parse($endsOn)->endOfDay() : null;

        $overlapSum = $this->sumOverlappingActiveAllocated(
            $tenantId,
            $landParcelId,
            $rangeStart,
            $rangeEnd,
            $excludeAllocationId
        );

        $totalParcel = (float) $parcel->total_acres;
        if ($overlapSum + $add > $totalParcel + 0.0001) {
            throw new \InvalidArgumentException(
                'Allocated area would exceed parcel total: overlapping active agreement allocations sum to '
                .number_format($overlapSum, 4, '.', '')
                ." and adding {$add} exceeds parcel total {$totalParcel}."
            );
        }
    }

    /**
     * Sum allocated_area for ACTIVE allocations whose date range overlaps [rangeStart, rangeEnd]
     * (open-ended allocations use a far-future end for overlap checks).
     */
    public function sumOverlappingActiveAllocated(
        string $tenantId,
        string $landParcelId,
        Carbon $rangeStart,
        ?Carbon $rangeEnd,
        ?string $excludeAllocationId = null
    ): float {
        $openEndCap = Carbon::parse('2099-12-31')->endOfDay();
        $effRangeEnd = $rangeEnd ?? $openEndCap;

        $q = AgreementAllocation::query()
            ->where('tenant_id', $tenantId)
            ->where('land_parcel_id', $landParcelId)
            ->where('status', AgreementAllocation::STATUS_ACTIVE);

        if ($excludeAllocationId) {
            $q->where('id', '!=', $excludeAllocationId);
        }

        // Intervals overlap: alloc_start <= rangeEnd AND (alloc_end IS NULL OR alloc_end >= rangeStart)
        $q->whereDate('starts_on', '<=', $effRangeEnd->format('Y-m-d'))
            ->where(function ($w) use ($rangeStart) {
                $w->whereNull('ends_on')
                    ->orWhereDate('ends_on', '>=', $rangeStart->format('Y-m-d'));
            });

        return (float) $q->sum('allocated_area');
    }
}
