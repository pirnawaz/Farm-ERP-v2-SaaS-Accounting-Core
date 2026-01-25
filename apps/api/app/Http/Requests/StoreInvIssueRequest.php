<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['required', 'string', 'max:100'],
            'store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'activity_id' => ['nullable', 'uuid'],
            'doc_date' => ['required', 'date', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
        ];
    }
}
