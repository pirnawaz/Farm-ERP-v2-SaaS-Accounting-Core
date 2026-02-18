<?php

namespace App\Domains\Accounting\PeriodClose;

use App\Models\CropCycle;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Precondition checks for period close. Keeps PeriodCloseService tidy.
 */
final class PeriodCloseGuard
{
    /**
     * Ensure crop cycle exists, belongs to tenant, and is OPEN.
     * Optionally require no ACTIVE projects in the cycle.
     *
     * @throws InvalidArgumentException
     */
    public function ensureCanClose(string $cropCycleId, string $tenantId, bool $requireProjectsNotActive = true): CropCycle
    {
        $cycle = CropCycle::where('id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($cycle->status !== 'OPEN') {
            throw new InvalidArgumentException('Crop cycle is not OPEN. Cannot close.', 422);
        }

        if ($requireProjectsNotActive) {
            $activeCount = Project::where('crop_cycle_id', $cropCycleId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'ACTIVE')
                ->count();
            if ($activeCount > 0) {
                throw new InvalidArgumentException('One or more projects are still ACTIVE. Close or complete projects first.', 422);
            }
        }

        return $cycle;
    }

    /**
     * Validate to_date is within crop cycle range (start_date <= to_date; end_date if set >= to_date).
     */
    public function validateCloseWindow(CropCycle $cycle, string $fromDate, string $toDate): void
    {
        if ($cycle->start_date && $toDate < $cycle->start_date->format('Y-m-d')) {
            throw new InvalidArgumentException('Close to_date is before crop cycle start date.', 422);
        }
        if ($cycle->end_date && $toDate > $cycle->end_date->format('Y-m-d')) {
            throw new InvalidArgumentException('Close to_date is after crop cycle end date.', 422);
        }
        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('from_date must be on or before to_date.', 422);
        }
    }
}
