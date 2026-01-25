<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class StoreCropActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'doc_no' => ['required', 'string', 'max:100'],
            'activity_type_id' => ['required', 'uuid', 'exists:crop_activity_types,id'],
            'activity_date' => ['required', 'date', 'date_format:Y-m-d'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => [
                'required',
                'uuid',
                'exists:projects,id',
                function ($attr, $value, $fail) {
                    if ($this->filled('crop_cycle_id')) {
                        $ok = Project::where('id', $value)->where('crop_cycle_id', $this->input('crop_cycle_id'))->exists();
                        if (!$ok) {
                            $fail('Project must belong to the selected crop cycle.');
                        }
                    }
                },
            ],
            'land_parcel_id' => ['nullable', 'uuid', 'exists:land_parcels,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'inputs' => ['nullable', 'array'],
            'inputs.*.store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'inputs.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'inputs.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'labour' => ['nullable', 'array'],
            'labour.*.worker_id' => ['required', 'uuid', 'exists:lab_workers,id'],
            'labour.*.rate_basis' => ['nullable', 'string', 'in:DAILY,HOURLY,PIECE'],
            'labour.*.units' => ['required', 'numeric', 'min:0.000001'],
            'labour.*.rate' => ['required', 'numeric', 'min:0'],
        ];

        return $rules;
    }
}
