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
            'doc_no' => ['nullable', 'string', 'max:100'],
            'store_id' => ['required', 'uuid', 'exists:inv_stores,id'],
            'crop_cycle_id' => ['required', 'uuid', 'exists:crop_cycles,id'],
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'activity_id' => ['nullable', 'uuid'],
            'machine_id' => ['nullable', 'uuid', 'exists:machines,id'],
            'doc_date' => ['required', 'date', 'date_format:Y-m-d'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', 'exists:inv_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.000001'],
            'allocation_mode' => ['required', 'string', 'in:SHARED,HARI_ONLY,FARMER_ONLY'],
            'hari_id' => ['required_if:allocation_mode,HARI_ONLY', 'nullable', 'uuid', 'exists:parties,id'],
            'sharing_rule_id' => ['nullable', 'uuid', 'exists:share_rules,id'],
            'landlord_share_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'hari_share_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $allocationMode = $this->input('allocation_mode');
            $sharingRuleId = $this->input('sharing_rule_id');
            $landlordPct = $this->input('landlord_share_pct');
            $hariPct = $this->input('hari_share_pct');

            // If SHARED and no sharing_rule_id, then percentages must sum to 100
            if ($allocationMode === 'SHARED' && !$sharingRuleId) {
                if ($landlordPct === null || $hariPct === null) {
                    $validator->errors()->add(
                        'allocation_mode',
                        'For SHARED allocation_mode, either sharing_rule_id or both landlord_share_pct and hari_share_pct must be provided'
                    );
                } elseif (abs((float) $landlordPct + (float) $hariPct - 100.0) > 0.01) {
                    $validator->errors()->add(
                        'landlord_share_pct',
                        'The sum of landlord_share_pct and hari_share_pct must equal 100'
                    );
                }
            }
        });
    }
}
