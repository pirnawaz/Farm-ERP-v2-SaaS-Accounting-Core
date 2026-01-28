<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['nullable', 'string', 'max:100'],
            'store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'reason' => ['required', 'string', 'in:LOSS,DAMAGE,COUNT_GAIN,COUNT_LOSS,OTHER'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'doc_date' => ['required', 'date', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty_delta' => ['required', 'numeric', function ($attr, $value, $fail) {
                if ((float) $value == 0) {
                    $fail('The qty_delta cannot be zero.');
                }
            }],
        ];
    }
}
