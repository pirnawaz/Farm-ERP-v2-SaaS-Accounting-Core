<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profit_split_landlord_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'profit_split_hari_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'kamdari_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'kamdar_party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'kamdari_order' => ['required', 'string', 'in:BEFORE_SPLIT'],
            'pool_definition' => ['required', 'string', 'in:REVENUE_MINUS_SHARED_COSTS'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $landlordPct = $this->input('profit_split_landlord_pct');
            $hariPct = $this->input('profit_split_hari_pct');

            if (abs(($landlordPct + $hariPct) - 100) > 0.01) {
                $validator->errors()->add(
                    'profit_split_landlord_pct',
                    'The sum of landlord and hari percentages must equal 100'
                );
            }
        });
    }
}
