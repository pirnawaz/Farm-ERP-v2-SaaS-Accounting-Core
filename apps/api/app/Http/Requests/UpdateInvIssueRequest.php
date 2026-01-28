<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['sometimes', 'required', 'string', 'max:100'],
            'store_id' => ['sometimes', 'required', 'uuid', 'exists:inv_stores,id'],
            'crop_cycle_id' => ['sometimes', 'required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => ['sometimes', 'required', 'uuid', 'exists:projects,id'],
            'activity_id' => ['nullable', 'uuid'],
            'machine_id' => ['nullable', 'uuid', 'exists:machines,id'],
            'doc_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'lines' => ['sometimes', 'required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
        ];
    }
}
