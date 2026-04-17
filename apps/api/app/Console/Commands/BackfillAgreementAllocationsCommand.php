<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\FieldBlock;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Project;
use App\Models\ProjectRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent backfill: links legacy projects to agreement allocations where a project-scoped
 * LAND_LEASE agreement and parcel scope (field block or land allocation) are present.
 * Copies settlement splits into agreement.terms.settlement when missing (for dual-read parity).
 */
class BackfillAgreementAllocationsCommand extends Command
{
    protected $signature = 'agreements:backfill-allocations {--dry-run : Report only, no writes}';

    protected $description = 'Backfill agreement_allocations and project agreement links from legacy project/agreement/field data (idempotent)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('Dry run: no database writes.');
        }

        $skippedNoAgreement = 0;
        $skippedNoParcel = 0;
        $linked = 0;
        $repaired = 0;

        $projects = Project::query()->orderBy('id')->get();

        foreach ($projects as $project) {
            $existingAlloc = AgreementAllocation::query()
                ->where('tenant_id', $project->tenant_id)
                ->where('backfilled_for_project_id', $project->id)
                ->first();

            if ($existingAlloc) {
                if (! $project->agreement_allocation_id && ! $dryRun) {
                    DB::transaction(function () use ($project, $existingAlloc) {
                        $project->update([
                            'agreement_id' => $existingAlloc->agreement_id,
                            'agreement_allocation_id' => $existingAlloc->id,
                        ]);
                    });
                    ++$repaired;
                    $this->line("Repaired project link from existing backfill row project_id={$project->id}");
                }
                continue;
            }

            if ($project->agreement_allocation_id) {
                continue;
            }

            $agreement = Agreement::query()
                ->where('tenant_id', $project->tenant_id)
                ->where('project_id', $project->id)
                ->where('agreement_type', Agreement::TYPE_LAND_LEASE)
                ->orderByDesc('priority')
                ->orderByDesc('effective_from')
                ->orderBy('id')
                ->first();

            if (! $agreement) {
                ++$skippedNoAgreement;
                $this->line("SKIP_NO_AGREEMENT project_id={$project->id} tenant_id={$project->tenant_id}");

                continue;
            }

            $landParcelId = null;
            $area = null;
            $legacyFieldId = null;

            if ($project->field_block_id) {
                $fb = FieldBlock::query()->where('tenant_id', $project->tenant_id)->where('id', $project->field_block_id)->first();
                if ($fb) {
                    $landParcelId = $fb->land_parcel_id;
                    $area = (string) $fb->area;
                    $legacyFieldId = $fb->id;
                }
            } elseif ($project->land_allocation_id) {
                $la = LandAllocation::query()->where('tenant_id', $project->tenant_id)->where('id', $project->land_allocation_id)->first();
                if ($la) {
                    $landParcelId = $la->land_parcel_id;
                    $area = (string) $la->allocated_acres;
                }
            }

            if (! $landParcelId || ! $area || (float) $area <= 0) {
                ++$skippedNoParcel;
                $this->warn("SKIP_NO_PARCEL_SCOPE project_id={$project->id}");

                continue;
            }

            LandParcel::query()->where('tenant_id', $project->tenant_id)->where('id', $landParcelId)->firstOrFail();

            $cropCycle = CropCycle::query()->where('tenant_id', $project->tenant_id)->where('id', $project->crop_cycle_id)->first();
            $startsOn = $cropCycle?->start_date?->format('Y-m-d') ?? $agreement->effective_from->format('Y-m-d');
            $endsOn = $cropCycle?->end_date?->format('Y-m-d');

            if ($dryRun) {
                $this->line("Would create allocation + link project_id={$project->id} agreement_id={$agreement->id} parcel_id={$landParcelId}");

                continue;
            }

            DB::transaction(function () use ($project, $agreement, $landParcelId, $area, $legacyFieldId, $startsOn, $endsOn, &$linked) {
                $allocation = AgreementAllocation::create([
                    'tenant_id' => $project->tenant_id,
                    'agreement_id' => $agreement->id,
                    'land_parcel_id' => $landParcelId,
                    'allocated_area' => $area,
                    'area_uom' => 'ACRE',
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'status' => AgreementAllocation::STATUS_ACTIVE,
                    'label' => 'Backfill',
                    'notes' => 'Created by agreements:backfill-allocations',
                    'legacy_field_id' => $legacyFieldId,
                    'backfilled_for_project_id' => $project->id,
                ]);

                $rule = ProjectRule::query()->where('project_id', $project->id)->first();
                if ($rule) {
                    $terms = is_array($agreement->terms) ? $agreement->terms : [];
                    if (empty($terms['settlement'])) {
                        $terms['settlement'] = [
                            'profit_split_landlord_pct' => (string) $rule->profit_split_landlord_pct,
                            'profit_split_hari_pct' => (string) $rule->profit_split_hari_pct,
                            'kamdari_pct' => (string) $rule->kamdari_pct,
                            'kamdari_order' => $rule->kamdari_order,
                            'pool_definition' => $rule->pool_definition,
                            'kamdar_party_id' => $rule->kamdar_party_id,
                        ];
                        $agreement->update(['terms' => $terms]);
                    }
                }

                $project->update([
                    'agreement_id' => $agreement->id,
                    'agreement_allocation_id' => $allocation->id,
                ]);

                ++$linked;
            });
        }

        $this->info("Done. New links: {$linked}, repaired links: {$repaired}, skipped (no agreement): {$skippedNoAgreement}, skipped (no parcel scope): {$skippedNoParcel}.");

        return self::SUCCESS;
    }
}
