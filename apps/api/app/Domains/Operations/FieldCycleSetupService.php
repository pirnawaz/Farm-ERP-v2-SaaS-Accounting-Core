<?php

namespace App\Domains\Operations;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\FieldBlock;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\Project;
use App\Services\LandAllocationService;
use App\Services\ProjectSettlementRuleResolver;
use App\Services\SystemPartyService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FieldCycleSetupService
{
    public function __construct(
        private SystemPartyService $systemPartyService,
        private LandAllocationService $landAllocationService,
        private ProjectSettlementRuleResolver $settlementRuleResolver
    ) {}

    public function ensureLandlordParty(string $tenantId): Party
    {
        return $this->systemPartyService->ensureSystemLandlordParty($tenantId);
    }

    public function loadCycle(string $tenantId, string $cropCycleId): CropCycle
    {
        $cycle = CropCycle::where('tenant_id', $tenantId)->where('id', $cropCycleId)->firstOrFail();
        if ($cycle->status !== 'OPEN') {
            throw ValidationException::withMessages([
                'crop_cycle_id' => ['Crop cycle must be OPEN to set up a field cycle.'],
            ])->status(422);
        }
        if (!$cycle->tenant_crop_item_id) {
            throw ValidationException::withMessages([
                'crop_cycle_id' => ['The selected crop cycle must have a crop assigned before creating a field cycle.'],
            ])->status(422);
        }
        return $cycle;
    }

    public function loadParcel(string $tenantId, string $landParcelId): LandParcel
    {
        return LandParcel::where('tenant_id', $tenantId)->where('id', $landParcelId)->firstOrFail();
    }

    /**
     * @return array{agreementId: string|null, agreementAllocationId: string|null}
     */
    public function validateAgreementLinks(
        string $tenantId,
        CropCycle $cycle,
        LandParcel $parcel,
        ?string $agreementId,
        ?string $agreementAllocationId
    ): array {
        if ($agreementAllocationId && !$agreementId) {
            throw ValidationException::withMessages([
                'agreement_id' => ['agreement_id is required when agreement_allocation_id is supplied.'],
            ])->status(422);
        }

        if ($agreementId) {
            $agreement = Agreement::where('tenant_id', $tenantId)->where('id', $agreementId)->firstOrFail();
            if ($agreement->agreement_type !== Agreement::TYPE_LAND_LEASE) {
                throw ValidationException::withMessages([
                    'agreement_id' => ['Only LAND_LEASE agreements can be linked in field cycle setup.'],
                ])->status(422);
            }
            if ($agreement->status !== Agreement::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'agreement_id' => ['Agreement must be ACTIVE.'],
                ])->status(422);
            }

            $probe = new Project();
            $probe->tenant_id = $tenantId;
            $probe->agreement_id = $agreementId;
            $probe->id = (string) Str::uuid();
            try {
                $this->settlementRuleResolver->resolveSettlementRule($probe);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages([
                    'agreement_id' => [$e->getMessage()],
                ])->status(422);
            }
        }

        if ($agreementAllocationId) {
            $alloc = AgreementAllocation::where('tenant_id', $tenantId)->where('id', $agreementAllocationId)->firstOrFail();
            if ((string) $alloc->agreement_id !== (string) $agreementId) {
                throw ValidationException::withMessages([
                    'agreement_allocation_id' => ['agreement_allocation_id must belong to the selected agreement.'],
                ])->status(422);
            }
            if ($alloc->status !== AgreementAllocation::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'agreement_allocation_id' => ['Agreement allocation is not active.'],
                ])->status(422);
            }
            if ((string) $alloc->land_parcel_id !== (string) $parcel->id) {
                throw ValidationException::withMessages([
                    'land_parcel_id' => ['Parcel must match the selected agreement allocation.'],
                ])->status(422);
            }
            $this->assertCycleOverlapsAgreementAllocation($cycle, $alloc);
        }

        return ['agreementId' => $agreementId, 'agreementAllocationId' => $agreementAllocationId];
    }

    public function ensureOwnerAllocation(string $tenantId, CropCycle $cycle, LandParcel $parcel, float $allocatedAcres): LandAllocation
    {
        $landAllocation = LandAllocation::where('tenant_id', $tenantId)
            ->where('crop_cycle_id', $cycle->id)
            ->where('land_parcel_id', $parcel->id)
            ->whereNull('party_id')
            ->first();

        if ($landAllocation) {
            if ((float) $landAllocation->allocated_acres !== $allocatedAcres) {
                $this->landAllocationService->validateAcreAllocation($tenantId, $parcel->id, $cycle->id, $allocatedAcres, $landAllocation->id);
                $landAllocation->update(['allocated_acres' => $allocatedAcres]);
            }
            return $landAllocation;
        }

        $this->landAllocationService->validateAcreAllocation($tenantId, $parcel->id, $cycle->id, $allocatedAcres);
        return LandAllocation::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => $allocatedAcres,
        ]);
    }

    public function ensureFieldBlock(
        string $tenantId,
        CropCycle $cycle,
        LandParcel $parcel,
        string $tenantCropItemId,
        ?string $name,
        ?float $area
    ): FieldBlock {
        $fieldBlock = FieldBlock::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'crop_cycle_id' => $cycle->id,
                'land_parcel_id' => $parcel->id,
                'tenant_crop_item_id' => $tenantCropItemId,
                'name' => $name,
            ],
            ['area' => $area]
        );

        if ($area !== null && (string) $fieldBlock->area !== (string) $area) {
            $fieldBlock->update(['area' => $area]);
        }

        return $fieldBlock;
    }

    public function assertFieldBlockNotLinkedToOtherProject(FieldBlock $fieldBlock, ?string $allowedProjectId = null): void
    {
        $existing = $fieldBlock->project()->first();
        if ($existing && (!$allowedProjectId || (string) $existing->id !== (string) $allowedProjectId)) {
            throw ValidationException::withMessages([
                'field_block_id' => ['Field block is already linked to another field cycle.'],
            ])->status(422);
        }
    }

    public function ensureProjectForFieldBlock(
        string $tenantId,
        CropCycle $cycle,
        LandAllocation $allocation,
        FieldBlock $fieldBlock,
        string $projectName,
        ?string $agreementId,
        ?string $agreementAllocationId
    ): Project {
        $landlord = $this->ensureLandlordParty($tenantId);

        $project = Project::firstOrCreate(
            ['field_block_id' => $fieldBlock->id],
            [
                'tenant_id' => $tenantId,
                'name' => $projectName,
                'party_id' => $landlord->id,
                'crop_cycle_id' => $cycle->id,
                'land_allocation_id' => $allocation->id,
                'agreement_id' => $agreementId,
                'agreement_allocation_id' => $agreementAllocationId,
                'status' => 'ACTIVE',
            ]
        );

        $updates = [];
        if ($project->name !== $projectName) $updates['name'] = $projectName;
        if (!$project->party_id) $updates['party_id'] = $landlord->id;
        if (!$project->land_allocation_id) $updates['land_allocation_id'] = $allocation->id;
        if (!$project->agreement_id && $agreementId) $updates['agreement_id'] = $agreementId;
        if (!$project->agreement_allocation_id && $agreementAllocationId) $updates['agreement_allocation_id'] = $agreementAllocationId;
        if (!empty($updates)) $project->update($updates);

        return $project;
    }

    private function assertCycleOverlapsAgreementAllocation(CropCycle $cycle, AgreementAllocation $alloc): void
    {
        $cycleStart = Carbon::parse($cycle->start_date)->startOfDay();
        $cycleEnd = Carbon::parse($cycle->end_date)->endOfDay();
        $allocStart = Carbon::parse($alloc->starts_on)->startOfDay();
        $allocEnd = $alloc->ends_on ? Carbon::parse($alloc->ends_on)->endOfDay() : Carbon::parse('2099-12-31')->endOfDay();

        if ($cycleStart->gt($allocEnd) || $cycleEnd->lt($allocStart)) {
            throw ValidationException::withMessages([
                'crop_cycle_id' => ['Crop cycle does not overlap agreement allocation dates.'],
            ])->status(422);
        }
    }
}

