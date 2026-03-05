<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlatformTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'temporary_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'string', 'in:tenant_admin,accountant,operator'],
        ];
    }
}
