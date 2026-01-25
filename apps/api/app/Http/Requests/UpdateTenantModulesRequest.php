<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modules' => ['required', 'array'],
            'modules.*.key' => ['required', 'string', 'exists:modules,key'],
            'modules.*.enabled' => ['required', 'boolean'],
        ];
    }
}
