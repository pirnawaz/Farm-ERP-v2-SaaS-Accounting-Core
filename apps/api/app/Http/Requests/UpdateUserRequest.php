<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/policy
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');
        $userId = $this->route('user') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', 'unique:users,email,' . $userId . ',id,tenant_id,' . $tenantId],
            'role' => ['sometimes', 'required', 'string', 'in:tenant_admin,accountant,operator'],
        ];
    }
}
