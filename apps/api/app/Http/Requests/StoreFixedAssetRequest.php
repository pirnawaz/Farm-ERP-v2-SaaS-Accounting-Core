<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'asset_code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:128'],
            'acquisition_date' => ['required', 'date', 'date_format:Y-m-d'],
            'in_service_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'currency_code' => ['required', 'string', 'size:3'],
            'acquisition_cost' => ['required', 'numeric', 'min:0'],
            'residual_value' => ['sometimes', 'numeric', 'min:0'],
            'useful_life_months' => ['required', 'integer', 'min:1'],
            'depreciation_method' => ['required', 'string', Rule::in(['STRAIGHT_LINE'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
