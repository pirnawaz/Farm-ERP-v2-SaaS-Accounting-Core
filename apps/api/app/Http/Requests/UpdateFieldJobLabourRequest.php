<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFieldJobLabourRequest extends FormRequest
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
                'sometimes',
                'uuid',
                $tenantId
                    ? Rule::exists('lab_workers', 'id')->where('tenant_id', $tenantId)
                    : 'exists:lab_workers,id',
            ],
            'rate_basis' => ['sometimes', 'string', 'in:DAILY,HOURLY,PIECE'],
            'units' => ['sometimes', 'numeric', 'min:0.000001'],
            'rate' => ['sometimes', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
