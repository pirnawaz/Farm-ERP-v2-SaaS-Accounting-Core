<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFieldJobInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();

        return [
            'store_id' => [
                'sometimes',
                'uuid',
                $tenantId
                    ? Rule::exists('inv_stores', 'id')->where('tenant_id', $tenantId)
                    : 'exists:inv_stores,id',
            ],
            'item_id' => [
                'sometimes',
                'uuid',
                $tenantId
                    ? Rule::exists('inv_items', 'id')->where('tenant_id', $tenantId)
                    : 'exists:inv_items,id',
            ],
            'qty' => ['sometimes', 'numeric', 'min:0.000001'],
            'unit_cost_snapshot' => ['nullable', 'numeric', 'min:0'],
            'line_total' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    private function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
