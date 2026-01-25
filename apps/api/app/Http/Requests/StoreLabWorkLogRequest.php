<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLabWorkLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['required', 'string', 'max:100'],
            'worker_id' => ['required', 'uuid', 'exists:lab_workers,id'],
            'work_date' => ['required', 'date', 'date_format:Y-m-d'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'activity_id' => ['nullable', 'uuid'],
            'rate_basis' => ['required', 'string', 'in:DAILY,HOURLY,PIECE'],
            'units' => ['required', 'numeric', 'min:0.000001'],
            'rate' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
