<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'string', 'in:IN,OUT'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'in:CASH,BANK'],
            'reference' => ['nullable', 'string', 'max:255'],
            'settlement_id' => ['nullable', 'uuid', 'exists:settlements,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
