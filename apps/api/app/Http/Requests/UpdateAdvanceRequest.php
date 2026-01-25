<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'type' => ['sometimes', 'required', 'string', 'in:HARI_ADVANCE,VENDOR_ADVANCE,LOAN'],
            'direction' => ['sometimes', 'required', 'string', 'in:IN,OUT'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'posting_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'method' => ['sometimes', 'required', 'string', 'in:CASH,BANK'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
