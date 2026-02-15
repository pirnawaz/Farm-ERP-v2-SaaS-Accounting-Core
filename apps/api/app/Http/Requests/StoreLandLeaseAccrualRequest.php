<?php

namespace App\Http\Requests;

use App\Domains\Operations\LandLease\LandLease;
use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLandLeaseAccrualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'tenant_admin';
    }

    public function rules(): array
    {
        $tenantId = TenantContext::getTenantId($this);
        $leaseId = $this->input('lease_id');

        $rules = [
            'lease_id' => ['required', 'uuid', Rule::exists('land_leases', 'id')->where('tenant_id', $tenantId)],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string'],
        ];

        if ($leaseId && $tenantId) {
            $lease = LandLease::where('id', $leaseId)->where('tenant_id', $tenantId)->first();
            if ($lease) {
                $rules['project_id'][] = Rule::in([$lease->project_id]);
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'project_id.in' => 'The selected project must match the lease\'s project.',
        ];
    }
}
