<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $tenantId)->ignore($userId),
            ],
            'role' => ['sometimes', 'required', 'string', 'in:tenant_admin,accountant,operator'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
