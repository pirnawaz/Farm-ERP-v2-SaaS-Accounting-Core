<?php

namespace App\Services;

use App\Exceptions\CropCycleClosedException;
use App\Exceptions\ProjectClosedException;
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

        // Block posting when cycle is CLOSED (or any non-OPEN status)
        if ($cycle->status !== 'OPEN') {
            throw new CropCycleClosedException();
        }
    }

    /**
     * Ensure the project is not CLOSED. Throws ProjectClosedException if CLOSED.
     * Call before creating posting groups / allocation rows for a project.
     *
     * @throws ProjectClosedException
     */
    public function ensureProjectNotClosed(string $projectId, string $tenantId): void
    {
        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($project->status === 'CLOSED') {
            throw new ProjectClosedException();
        }
    }

    /**
     * Resolve project to crop_cycle_id, then ensure the crop cycle is OPEN and project is not CLOSED.
     *
     * @throws CropCycleClosedException
     * @throws ProjectClosedException
     */
    public function ensureCropCycleOpenForProject(string $projectId, string $tenantId): void
    {
        $this->ensureProjectNotClosed($projectId, $tenantId);

        $project = Project::where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (!$project->crop_cycle_id) {
            throw new CropCycleClosedException('Project has no crop cycle.');
        }

        $this->ensureCropCycleOpen($project->crop_cycle_id, $tenantId);
    }
}
