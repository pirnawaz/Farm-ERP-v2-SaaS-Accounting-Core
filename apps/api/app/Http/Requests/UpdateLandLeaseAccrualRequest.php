<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLandLeaseAccrualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'tenant_admin';
    }

    public function rules(): array
    {
        $rules = [
            'period_start' => ['sometimes', 'date'],
            'period_end' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'memo' => ['nullable', 'string'],
        ];

        if ($this->filled('period_start') && $this->filled('period_end')) {
            $rules['period_end'] = array_merge($rules['period_end'] ?? [], ['after_or_equal:period_start']);
        }

        return $rules;
    }
}
