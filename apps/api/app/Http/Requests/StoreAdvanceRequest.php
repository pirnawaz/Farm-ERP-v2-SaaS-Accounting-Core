<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdvanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'type' => ['required', 'string', 'in:HARI_ADVANCE,VENDOR_ADVANCE,LOAN'],
            'direction' => ['required', 'string', 'in:IN,OUT'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'posting_date' => ['required', 'date', 'date_format:Y-m-d'],
            'method' => ['required', 'string', 'in:CASH,BANK'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
