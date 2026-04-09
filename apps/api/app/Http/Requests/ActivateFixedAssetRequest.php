<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActivateFixedAssetRequest extends FormRequest
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
            'source_account' => ['required', 'string', Rule::in(['BANK', 'CASH', 'AP_CLEARING', 'EQUITY_INJECTION'])],
        ];
    }
}
