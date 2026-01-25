<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'party_types' => ['sometimes', 'required', 'array'],
            'party_types.*' => ['required', 'string', 'in:HARI,KAMDAR,VENDOR,BUYER,LENDER,CONTRACTOR,LANDLORD'],
        ];
    }
}
