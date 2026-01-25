<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCropActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doc_no' => ['sometimes', 'string', 'max:100'],
            'activity_type_id' => ['sometimes', 'uuid', 'exists:crop_activity_types,id'],
            'activity_date' => ['sometimes', 'date', 'date_format:Y-m-d'],
            'crop_cycle_id' => ['sometimes', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => [
                'sometimes',
                'uuid',
                'exists:projects,id',
                function ($attr, $value, $fail) {
                    $cropCycleId = $this->input('crop_cycle_id');
                    if ($cropCycleId && $value) {
                        $ok = Project::where('id', $value)->where('crop_cycle_id', $cropCycleId)->exists();
                        if (!$ok) {
                            $fail('Project must belong to the selected crop cycle.');
                        }
                    }
                },
            ],
            'land_parcel_id' => ['nullable', 'uuid', 'exists:land_parcels,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'inputs' => ['sometimes', 'array'],
            'inputs.*.store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'inputs.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'inputs.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'labour' => ['sometimes', 'array'],
            'labour.*.worker_id' => ['required', 'uuid', 'exists:lab_workers,id'],
            'labour.*.rate_basis' => ['nullable', 'string', 'in:DAILY,HOURLY,PIECE'],
            'labour.*.units' => ['required', 'numeric', 'min:0.000001'],
            'labour.*.rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
