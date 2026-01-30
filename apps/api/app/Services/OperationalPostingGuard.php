<?php

namespace App\Services;

use App\Exceptions\CropCycleClosedException;
use App\Models\CropCycle;
use App\Models\Project;

class OperationalPostingGuard
{
    /**
     * Ensure the crop cycle is OPEN. Throws CropCycleClosedException if CLOSED.
     *
     * @throws CropCycleClosedException
     */
    public function ensureCropCycleOpen(string $cropCycleId, string $tenantId): void
    {
        $cycle = CropCycle::where('id', $cropCycleId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($cycle->status !== 'OPEN') {
            throw new CropCycleClosedException();
        }
    }

    /**
     * Resolve project to crop_cycle_id, then ensure the crop cycle is OPEN.
     *
     * @throws CropCycleClosedException
     */
    public function ensureCropCycleOpenForProject(string $projectId, string $tenantId): void
    {
        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (!$project->crop_cycle_id) {
            throw new CropCycleClosedException('Project has no crop cycle.');
        }

        $this->ensureCropCycleOpen($project->crop_cycle_id, $tenantId);
    }
}
