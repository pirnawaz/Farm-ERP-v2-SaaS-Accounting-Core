<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlatformTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'initial_admin_email' => ['required', 'email', 'max:255'],
            'initial_admin_password' => ['required', 'string', 'min:8'],
            'initial_admin_name' => ['required', 'string', 'max:255'],
        ];
    }
}
