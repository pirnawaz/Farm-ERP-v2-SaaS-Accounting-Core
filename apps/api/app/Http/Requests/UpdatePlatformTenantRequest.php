<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'status' => ['sometimes', 'required', 'string', 'in:active,suspended'],
            'plan_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
