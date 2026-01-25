<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostInvAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
