<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'buyer_party_id' => ['sometimes', 'required', 'uuid', 'exists:parties,id'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'crop_cycle_id' => ['nullable', 'uuid', 'exists:crop_cycles,id'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'posting_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'sale_no' => ['nullable', 'string', 'max:255'],
            'sale_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
            'sale_lines' => ['nullable', 'array'],
            'sale_lines.*.inventory_item_id' => ['required_with:sale_lines', 'uuid', 'exists:inv_items,id'],
            'sale_lines.*.store_id' => ['required_with:sale_lines', 'uuid', 'exists:inv_stores,id'],
            'sale_lines.*.quantity' => ['required_with:sale_lines', 'numeric', 'min:0.001'],
            'sale_lines.*.uom' => ['nullable', 'string', 'max:255'],
            'sale_lines.*.unit_price' => ['required_with:sale_lines', 'numeric', 'min:0.01'],
        ];
    }
}
