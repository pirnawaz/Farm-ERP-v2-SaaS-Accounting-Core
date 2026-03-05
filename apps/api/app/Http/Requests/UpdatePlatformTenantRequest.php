<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformTenantRequest extends FormRequest
{
    private const SLUG_RESERVED = ['app', 'api', 'platform', 'admin', 'login', 'dev', 'www', 'mail', 'support', 'help'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->route('id');
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('tenants', 'slug')->ignore($tenantId),
                Rule::notIn(self::SLUG_RESERVED),
            ],
            'status' => ['sometimes', 'required', 'string', 'in:active,suspended,archived'],
            'plan_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
