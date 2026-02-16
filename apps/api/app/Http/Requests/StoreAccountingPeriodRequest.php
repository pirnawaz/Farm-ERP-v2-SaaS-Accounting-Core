<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
