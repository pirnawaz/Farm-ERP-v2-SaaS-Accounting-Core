<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $harvests = DB::table('harvests')->whereNull('project_id')->get();
        foreach ($harvests as $h) {
            $projectId = null;

            // Only backfill when harvest has land_parcel_id; match by project whose land_allocation resolves to same parcel
            if ($h->land_parcel_id) {
                $matches = DB::table('projects')
                    ->join('land_allocations', 'projects.land_allocation_id', '=', 'land_allocations.id')
                    ->where('projects.tenant_id', $h->tenant_id)
                    ->where('projects.crop_cycle_id', $h->crop_cycle_id)
                    ->where('land_allocations.land_parcel_id', $h->land_parcel_id)
                    ->pluck('projects.id');

                if ($matches->count() === 1) {
                    $projectId = $matches->first();
                }
                // If 0 or >1 matches, leave project_id NULL (do not guess)
            }

            if ($projectId) {
                DB::table('harvests')->where('id', $h->id)->update(['project_id' => $projectId]);
            }
        }
    }

    public function down(): void
    {
        // Cannot safely reverse backfill
    }
};
