<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'uuid', 'exists:accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:65535'],
            'lines.*.debit_amount' => ['required', 'numeric', 'min:0'],
            'lines.*.credit_amount' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines', []);
            foreach ($lines as $i => $line) {
                $dr = (float) ($line['debit_amount'] ?? 0);
                $cr = (float) ($line['credit_amount'] ?? 0);
                $positive = ($dr > 0 ? 1 : 0) + ($cr > 0 ? 1 : 0);
                if ($positive !== 1) {
                    $validator->errors()->add(
                        "lines.{$i}",
                        'Exactly one of debit_amount or credit_amount must be positive.'
                    );
                }
            }
        });
    }
}
