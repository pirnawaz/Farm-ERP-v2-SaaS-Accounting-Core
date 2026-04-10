<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\ProjectPlanCost;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectPlanRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'project_id' => ['required', 'uuid', $exists('projects')],
            'crop_cycle_id' => ['required', 'uuid', $exists('crop_cycles')],
            'status' => ['nullable', 'string', Rule::in([ProjectPlan::STATUS_DRAFT, ProjectPlan::STATUS_ACTIVE])],
            'costs' => 'nullable|array',
            'costs.*.cost_type' => ['required', 'string', Rule::in([
                ProjectPlanCost::COST_TYPE_INPUT,
                ProjectPlanCost::COST_TYPE_LABOUR,
                ProjectPlanCost::COST_TYPE_MACHINERY,
            ])],
            'costs.*.expected_quantity' => 'nullable|numeric',
            'costs.*.expected_cost' => 'nullable|numeric',
            'yields' => 'nullable|array',
            'yields.*.expected_quantity' => 'nullable|numeric',
            'yields.*.expected_unit_value' => 'nullable|numeric',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $tenantId = TenantContext::getTenantId($this);
            $project = Project::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $this->input('project_id'))
                ->first();
            if ($project === null) {
                return;
            }
            if ((string) $project->crop_cycle_id !== (string) $this->input('crop_cycle_id')) {
                $validator->errors()->add('crop_cycle_id', 'Crop cycle does not match this project.');
            }
        });
    }
}
