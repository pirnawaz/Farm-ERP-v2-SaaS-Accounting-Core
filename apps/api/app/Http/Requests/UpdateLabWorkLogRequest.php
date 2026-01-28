<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLabWorkLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['sometimes', 'required', 'string', 'max:100'],
            'worker_id' => ['sometimes', 'required', 'uuid', 'exists:lab_workers,id'],
            'work_date' => ['sometimes', 'required', 'date', 'date_format:Y-m-d'],
            'crop_cycle_id' => ['sometimes', 'required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => ['sometimes', 'required', 'uuid', 'exists:projects,id'],
            'activity_id' => ['nullable', 'uuid'],
            'machine_id' => ['nullable', 'uuid', 'exists:machines,id'],
            'rate_basis' => ['sometimes', 'required', 'string', 'in:DAILY,HOURLY,PIECE'],
            'units' => ['sometimes', 'required', 'numeric', 'min:0.000001'],
            'rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
