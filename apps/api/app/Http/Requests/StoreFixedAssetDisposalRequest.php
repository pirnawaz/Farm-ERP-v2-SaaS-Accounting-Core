<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFixedAssetDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date', 'date_format:Y-m-d'],
            'proceeds_amount' => ['required', 'numeric', 'min:0'],
            'proceeds_account' => ['nullable', 'string', Rule::in(['BANK', 'CASH'])],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $proceeds = (float) ($this->input('proceeds_amount') ?? 0);
            if ($proceeds > 0 && ! $this->filled('proceeds_account')) {
                $v->errors()->add('proceeds_account', 'proceeds_account is required when proceeds_amount is greater than zero.');
            }
        });
    }
}
