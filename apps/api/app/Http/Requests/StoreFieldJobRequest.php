<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFieldJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();

        $docNoRules = ['nullable', 'string', 'max:100'];
        if ($tenantId) {
            $docNoRules[] = Rule::unique('field_jobs', 'doc_no')->where('tenant_id', $tenantId);
        }

        return [
            'doc_no' => $docNoRules,
            'job_date' => ['required', 'date', 'date_format:Y-m-d'],
            'project_id' => [
                'required',
                'uuid',
                $tenantId
                    ? Rule::exists('projects', 'id')->where('tenant_id', $tenantId)
                    : 'exists:projects,id',
            ],
            'crop_activity_type_id' => [
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('crop_activity_types', 'id')->where('tenant_id', $tenantId)
                    : 'exists:crop_activity_types,id',
            ],
            'production_unit_id' => [
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('production_units', 'id')->where('tenant_id', $tenantId)
                    : 'exists:production_units,id',
            ],
            'land_parcel_id' => [
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('land_parcels', 'id')->where('tenant_id', $tenantId)
                    : 'exists:land_parcels,id',
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
