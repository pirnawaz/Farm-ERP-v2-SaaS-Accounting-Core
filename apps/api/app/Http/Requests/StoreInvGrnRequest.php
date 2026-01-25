<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['required', 'string', 'max:100'],
            'supplier_party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'doc_date' => ['required', 'date', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }
}
