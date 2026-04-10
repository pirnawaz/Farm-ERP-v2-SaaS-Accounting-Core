<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddFieldJobMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'machine_id' => [
                'required',
                'uuid',
                $tenantId
                    ? Rule::exists('machines', 'id')->where('tenant_id', $tenantId)
                    : 'exists:machines,id',
            ],
            'usage_qty' => ['required', 'numeric', 'min:0'],
            'meter_unit_snapshot' => ['nullable', 'string', 'max:50'],
            'rate_snapshot' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'source_work_log_id' => [
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('machine_work_logs', 'id')->where('tenant_id', $tenantId)
                    : 'exists:machine_work_logs,id',
            ],
            'source_charge_id' => [
                'nullable',
                'uuid',
                $tenantId
                    ? Rule::exists('machinery_charges', 'id')->where('tenant_id', $tenantId)
                    : 'exists:machinery_charges,id',
            ],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
