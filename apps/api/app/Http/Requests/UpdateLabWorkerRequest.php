<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLabWorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'worker_no' => ['nullable', 'string', 'max:100'],
            'worker_type' => ['sometimes', 'nullable', 'string', 'in:HARI,STAFF,CONTRACT'],
            'rate_basis' => ['sometimes', 'nullable', 'string', 'in:DAILY,HOURLY,PIECE'],
            'default_rate' => ['nullable', 'numeric', 'min:0'],
            'phone' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
