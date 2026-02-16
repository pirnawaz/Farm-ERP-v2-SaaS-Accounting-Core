<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reversal_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'memo' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
