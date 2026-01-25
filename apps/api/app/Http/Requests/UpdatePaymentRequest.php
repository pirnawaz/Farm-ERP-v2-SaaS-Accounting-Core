<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['sometimes', 'required', 'string', 'in:IN,OUT'],
            'party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'payment_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'method' => ['sometimes', 'required', 'string', 'in:CASH,BANK'],
            'reference' => ['nullable', 'string', 'max:255'],
            'settlement_id' => ['nullable', 'uuid', 'exists:settlements,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
