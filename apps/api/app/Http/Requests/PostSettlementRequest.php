<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'up_to_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'apply_advance_offset' => ['nullable', 'boolean'],
            'advance_offset_amount' => ['nullable', 'numeric', 'min:0', 'required_if:apply_advance_offset,true'],
        ];
    }
}
