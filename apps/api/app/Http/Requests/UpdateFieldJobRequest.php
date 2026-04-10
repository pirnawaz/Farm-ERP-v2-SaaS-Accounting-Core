<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFieldJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();
        $fieldJobId = $this->route('id');

        return [
            'doc_no' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                $tenantId && $fieldJobId
                    ? Rule::unique('field_jobs', 'doc_no')->where('tenant_id', $tenantId)->ignore($fieldJobId)
                    : 'nullable',
            ],
            'job_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'project_id' => [
                'sometimes',
                'uuid',
                $tenantId
                    ? Rule::exists('projects', 'id')->where('tenant_id', $tenantId)
                    : 'exists:projects,id',
            ],
            'crop_activity_type_id' => [
                'sometimes',
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('crop_activity_types', 'id')->where('tenant_id', $tenantId)
                    : 'exists:crop_activity_types,id',
            ],
            'production_unit_id' => [
                'sometimes',
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('production_units', 'id')->where('tenant_id', $tenantId)
                    : 'exists:production_units,id',
            ],
            'land_parcel_id' => [
                'sometimes',
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('land_parcels', 'id')->where('tenant_id', $tenantId)
                    : 'exists:land_parcels,id',
            ],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
