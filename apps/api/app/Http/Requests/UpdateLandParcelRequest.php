<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLandParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'total_acres' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
