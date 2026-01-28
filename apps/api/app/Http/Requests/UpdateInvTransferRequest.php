<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from_store_id' => ['sometimes', 'required', 'uuid', 'exists:inv_stores,id', 'different:to_store_id'],
            'to_store_id' => ['sometimes', 'required', 'uuid', 'exists:inv_stores,id', 'different:from_store_id'],
            'doc_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'lines' => ['sometimes', 'required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
        ];
    }
}
