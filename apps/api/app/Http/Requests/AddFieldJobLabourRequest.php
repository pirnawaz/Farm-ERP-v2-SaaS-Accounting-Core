<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddFieldJobLabourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'worker_id' => [
                'required',
                'uuid',
                $tenantId
                    ? Rule::exists('lab_workers', 'id')->where('tenant_id', $tenantId)
                    : 'exists:lab_workers,id',
            ],
            'rate_basis' => ['nullable', 'string', 'in:DAILY,HOURLY,PIECE'],
            'units' => ['required', 'numeric', 'min:0.000001'],
            'rate' => ['required', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
