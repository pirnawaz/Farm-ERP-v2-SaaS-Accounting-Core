<?php

namespace App\Http\Requests;

use App\Models\HarvestShareLine;

/**
 * Merges missing fields from the existing share line so validation matches full row semantics (PUT-style).
 */
class UpdateHarvestShareLineRequest extends AddHarvestShareLineRequest
{
    protected function prepareForValidation(): void
    {
        $tenantId = $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
        $harvestId = $this->route('id');
        $shareLineId = $this->route('shareLineId');

        if (! $harvestId || ! $shareLineId || ! $tenantId) {
            return;
        }

        $line = HarvestShareLine::where('id', $shareLineId)
            ->where('harvest_id', $harvestId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $line) {
            abort(404);
        }

        $keys = [
            'harvest_line_id',
            'recipient_role',
            'settlement_mode',
            'share_basis',
            'share_value',
            'ratio_numerator',
            'ratio_denominator',
            'remainder_bucket',
            'beneficiary_party_id',
            'machine_id',
            'worker_id',
            'source_field_job_id',
            'source_lab_work_log_id',
            'source_machinery_charge_id',
            'source_settlement_id',
            'inventory_item_id',
            'store_id',
            'sort_order',
            'rule_snapshot',
            'notes',
        ];

        $merge = [];
        foreach ($keys as $key) {
            if (! $this->has($key)) {
                $merge[$key] = $line->{$key};
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
