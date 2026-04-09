<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostLoanDrawdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'funding_account' => ['required', 'string', Rule::in(['CASH', 'BANK'])],
        ];
    }
}
