<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseAccountingPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
