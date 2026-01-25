<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by middleware/policy
    }

    public function rules(): array
    {
        $tenantId = $this->attributes->get('tenant_id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,NULL,id,tenant_id,' . $tenantId],
            'role' => ['required', 'string', 'in:tenant_admin,accountant,operator'],
        ];
    }
}
