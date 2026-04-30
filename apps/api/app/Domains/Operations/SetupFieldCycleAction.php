<?php

namespace App\Domains\Operations;

use App\Models\LandParcel;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetupFieldCycleAction
{
    public function __construct(
        private FieldCycleSetupService $setupService
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(string $tenantId, array $payload): Project
    {
        return DB::transaction(function () use ($tenantId, $payload) {
            $cycle = $this->setupService->loadCycle($tenantId, (string) $payload['crop_cycle_id']);
            $parcel = $this->setupService->loadParcel($tenantId, (string) $payload['land_parcel_id']);
            $links = $this->setupService->validateAgreementLinks(
                $tenantId,
                $cycle,
                $parcel,
                $payload['agreement_id'] ?? null,
                $payload['agreement_allocation_id'] ?? null
            );

            $allocatedAcres = (float) $payload['allocated_acres'];
            $landAllocation = $this->setupService->ensureOwnerAllocation($tenantId, $cycle, $parcel, $allocatedAcres);

            $fieldBlockName = isset($payload['field_block_name']) && trim((string) $payload['field_block_name']) !== ''
                ? trim((string) $payload['field_block_name'])
                : null;

            $fieldBlock = null;
            if ($fieldBlockName !== null) {
                $fieldBlock = $this->setupService->ensureFieldBlock(
                    $tenantId,
                    $cycle,
                    $parcel,
                    (string) $cycle->tenant_crop_item_id,
                    $fieldBlockName,
                    null
                );
                $this->setupService->assertFieldBlockNotLinkedToOtherProject($fieldBlock, $payload['project_id'] ?? null);
            }

            $projectId = $payload['project_id'] ?? null;
            if ($projectId) {
                $project = Project::where('tenant_id', $tenantId)->where('id', $projectId)->firstOrFail();

                $this->assertNoConflict('land_allocation_id', $project->land_allocation_id, $landAllocation->id);
                $this->assertNoConflict('field_block_id', $project->field_block_id, $fieldBlock?->id);
                $this->assertNoConflict('agreement_id', $project->agreement_id, $links['agreementId']);
                $this->assertNoConflict('agreement_allocation_id', $project->agreement_allocation_id, $links['agreementAllocationId']);

                $landlord = $this->setupService->ensureLandlordParty($tenantId);
                $project->update([
                    'name' => $payload['project_name'],
                    'party_id' => $project->party_id ?: $landlord->id,
                    'land_allocation_id' => $project->land_allocation_id ?: $landAllocation->id,
                    'field_block_id' => $project->field_block_id ?: ($fieldBlock?->id),
                    'agreement_id' => $project->agreement_id ?: $links['agreementId'],
                    'agreement_allocation_id' => $project->agreement_allocation_id ?: $links['agreementAllocationId'],
                ]);

                return $project;
            }

            if ($fieldBlock === null) {
                // Without a field block, enforce 1:1 between allocation and project (to prevent ambiguous duplicates).
                $existingProjectForAllocation = Project::where('tenant_id', $tenantId)
                    ->where('land_allocation_id', $landAllocation->id)
                    ->whereNotNull('land_allocation_id')
                    ->first();
                if ($existingProjectForAllocation) {
                    throw ValidationException::withMessages([
                        'land_parcel_id' => ['A field cycle already exists for this allocation. Use completion mode instead.'],
                    ])->status(422);
                }
            }

            $landlord = $this->setupService->ensureLandlordParty($tenantId);
            $project = Project::create([
                'tenant_id' => $tenantId,
                'name' => $payload['project_name'],
                'party_id' => $landlord->id,
                'crop_cycle_id' => $cycle->id,
                'land_allocation_id' => $landAllocation->id,
                'field_block_id' => $fieldBlock?->id,
                'agreement_id' => $links['agreementId'],
                'agreement_allocation_id' => $links['agreementAllocationId'],
                'status' => 'ACTIVE',
            ]);

            return $project;
        });
    }

    private function assertNoConflict(string $field, $existing, $incoming): void
    {
        if ($incoming === null || $incoming === '') {
            return;
        }
        if ($existing === null || $existing === '') {
            return;
        }
        if ((string) $existing !== (string) $incoming) {
            throw ValidationException::withMessages([
                $field => ["$field conflicts with existing project setup."],
            ])->status(422);
        }
    }
}

