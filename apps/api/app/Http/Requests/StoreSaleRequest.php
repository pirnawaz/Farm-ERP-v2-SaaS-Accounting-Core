<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'buyer_party_id' => ['required', 'uuid', 'exists:parties,id'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'sale_no' => ['nullable', 'string', 'max:255'],
            'sale_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
