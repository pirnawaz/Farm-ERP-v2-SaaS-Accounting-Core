<?php

namespace App\Http\Requests;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Project;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FieldCycleSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = TenantContext::getTenantId($this);
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'crop_cycle_id' => ['required', 'uuid', $exists('crop_cycles')],
            'land_parcel_id' => ['required', 'uuid', $exists('land_parcels')],
            'allocated_acres' => ['required', 'numeric', 'gt:0'],

            'project_name' => ['required', 'string', 'max:255'],
            'field_block_name' => ['nullable', 'string', 'max:255'],

            // Optional completion path
            'project_id' => ['nullable', 'uuid', $exists('projects')],

            // Optional agreement links
            'agreement_id' => ['nullable', 'uuid', $exists('agreements')],
            'agreement_allocation_id' => ['nullable', 'uuid', $exists('agreement_allocations')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tenantId = TenantContext::getTenantId($this);
            $cycleId = (string) $this->input('crop_cycle_id');
            $parcelId = (string) $this->input('land_parcel_id');

            CropCycle::where('tenant_id', $tenantId)->where('id', $cycleId)->firstOrFail();
            LandParcel::where('tenant_id', $tenantId)->where('id', $parcelId)->firstOrFail();

            $agreementId = $this->input('agreement_id');
            $agreementAllocationId = $this->input('agreement_allocation_id');
            if (($agreementAllocationId !== null && $agreementAllocationId !== '') && ($agreementId === null || $agreementId === '')) {
                $validator->errors()->add('agreement_id', 'agreement_id is required when agreement_allocation_id is supplied.');
                return;
            }

            if ($agreementId !== null && $agreementId !== '') {
                $agreement = Agreement::where('tenant_id', $tenantId)->where('id', $agreementId)->firstOrFail();
                if ($agreement->agreement_type !== Agreement::TYPE_LAND_LEASE) {
                    $validator->errors()->add('agreement_id', 'Only LAND_LEASE agreements can be linked in field cycle setup.');
                    return;
                }
                if ($agreement->status !== Agreement::STATUS_ACTIVE) {
                    $validator->errors()->add('agreement_id', 'Agreement must be ACTIVE.');
                    return;
                }
            }

            if ($agreementAllocationId !== null && $agreementAllocationId !== '') {
                $alloc = AgreementAllocation::where('tenant_id', $tenantId)->where('id', $agreementAllocationId)->firstOrFail();
                if ((string) $alloc->agreement_id !== (string) $agreementId) {
                    $validator->errors()->add('agreement_allocation_id', 'agreement_allocation_id must belong to the selected agreement.');
                    return;
                }
            }

            $projectId = $this->input('project_id');
            if ($projectId !== null && $projectId !== '') {
                $project = Project::where('tenant_id', $tenantId)->where('id', $projectId)->firstOrFail();
                if ((string) $project->crop_cycle_id !== $cycleId) {
                    $validator->errors()->add('crop_cycle_id', 'Crop cycle does not match the selected project.');
                }
            }
        });
    }
}

