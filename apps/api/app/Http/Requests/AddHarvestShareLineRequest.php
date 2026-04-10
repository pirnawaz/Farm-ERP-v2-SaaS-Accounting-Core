<?php

namespace App\Http\Requests;

use App\Models\HarvestShareLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Draft-only harvest share line create.
 *
 * Posting-time checks (e.g. share qty vs harvest line qty, Field Job linkage, WIP value) are deferred to Phase 3C;
 * see inline comments below.
 */
class AddHarvestShareLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = $this->tenantId();

        $tenantExists = fn (string $table, string $column = 'id') => $tenantId
            ? Rule::exists($table, $column)->where('tenant_id', $tenantId)
            : "exists:{$table},{$column}";

        return [
            'harvest_line_id' => ['nullable', 'uuid', $tenantExists('harvest_lines')],
            'recipient_role' => ['required', 'string', Rule::in([
                HarvestShareLine::RECIPIENT_OWNER,
                HarvestShareLine::RECIPIENT_MACHINE,
                HarvestShareLine::RECIPIENT_LABOUR,
                HarvestShareLine::RECIPIENT_LANDLORD,
                HarvestShareLine::RECIPIENT_CONTRACTOR,
            ])],
            'settlement_mode' => ['required', 'string', Rule::in([
                HarvestShareLine::SETTLEMENT_IN_KIND,
                HarvestShareLine::SETTLEMENT_CASH,
            ])],
            'share_basis' => ['required', 'string', Rule::in([
                HarvestShareLine::BASIS_FIXED_QTY,
                HarvestShareLine::BASIS_PERCENT,
                HarvestShareLine::BASIS_RATIO,
                HarvestShareLine::BASIS_REMAINDER,
            ])],
            'share_value' => ['nullable', 'numeric'],
            'ratio_numerator' => ['nullable', 'numeric'],
            'ratio_denominator' => ['nullable', 'numeric'],
            'remainder_bucket' => ['nullable', 'boolean'],
            'beneficiary_party_id' => ['nullable', 'uuid', $tenantExists('parties')],
            'machine_id' => ['nullable', 'uuid', $tenantExists('machines')],
            'worker_id' => ['nullable', 'uuid', $tenantExists('lab_workers')],
            'source_field_job_id' => ['nullable', 'uuid', $tenantExists('field_jobs')],
            'source_lab_work_log_id' => ['nullable', 'uuid', $tenantExists('lab_work_logs')],
            'source_machinery_charge_id' => ['nullable', 'uuid', $tenantExists('machinery_charges')],
            'source_settlement_id' => ['nullable', 'uuid', $tenantExists('settlements')],
            'inventory_item_id' => ['nullable', 'uuid', $tenantExists('inv_items')],
            'store_id' => ['nullable', 'uuid', $tenantExists('inv_stores')],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'rule_snapshot' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $basis = $data['share_basis'] ?? null;
            $role = $data['recipient_role'] ?? null;
            $remainder = filter_var($data['remainder_bucket'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($basis !== HarvestShareLine::BASIS_REMAINDER && $remainder) {
                $v->errors()->add('remainder_bucket', 'remainder_bucket may only be true when share_basis is REMAINDER.');
            }

            if ($basis === HarvestShareLine::BASIS_FIXED_QTY) {
                if (! isset($data['share_value']) || $data['share_value'] === '' || (float) $data['share_value'] <= 0) {
                    $v->errors()->add('share_value', 'A positive share_value is required when share_basis is FIXED_QTY.');
                }
            }

            if ($basis === HarvestShareLine::BASIS_PERCENT) {
                if (! isset($data['share_value']) || $data['share_value'] === '') {
                    $v->errors()->add('share_value', 'share_value is required when share_basis is PERCENT.');
                } else {
                    $pct = (float) $data['share_value'];
                    if ($pct < 0 || $pct > 100) {
                        $v->errors()->add('share_value', 'share_value must be between 0 and 100 when share_basis is PERCENT.');
                    }
                }
            }

            if ($basis === HarvestShareLine::BASIS_RATIO) {
                $n = isset($data['ratio_numerator']) ? (float) $data['ratio_numerator'] : null;
                $d = isset($data['ratio_denominator']) ? (float) $data['ratio_denominator'] : null;
                if ($n === null || $n <= 0) {
                    $v->errors()->add('ratio_numerator', 'ratio_numerator must be greater than 0 when share_basis is RATIO.');
                }
                if ($d === null || $d <= 0) {
                    $v->errors()->add('ratio_denominator', 'ratio_denominator must be greater than 0 when share_basis is RATIO.');
                }
            }

            if ($basis === HarvestShareLine::BASIS_REMAINDER) {
                if (! $remainder) {
                    $v->errors()->add('remainder_bucket', 'remainder_bucket must be true when share_basis is REMAINDER.');
                }
                if (isset($data['share_value']) && $data['share_value'] !== null && $data['share_value'] !== '') {
                    $v->errors()->add('share_value', 'share_value must be empty when share_basis is REMAINDER.');
                }
            }

            if ($role === HarvestShareLine::RECIPIENT_MACHINE && empty($data['machine_id'])) {
                $v->errors()->add('machine_id', 'machine_id is required when recipient_role is MACHINE.');
            }

            if ($role === HarvestShareLine::RECIPIENT_LABOUR && empty($data['worker_id'])) {
                $v->errors()->add('worker_id', 'worker_id is required when recipient_role is LABOUR.');
            }

            if ($role === HarvestShareLine::RECIPIENT_OWNER && ! empty($data['beneficiary_party_id'])) {
                $v->errors()->add('beneficiary_party_id', 'beneficiary_party_id must be empty when recipient_role is OWNER.');
            }

            // Phase 3C: optional warning — MACHINE share without source_field_job_id may not auto-net Field Job machinery GL.
            // Hard enforcement deferred to posting preview.
        });
    }

    protected function tenantId(): ?string
    {
        return $this->attributes->get('tenant_id') ?? $this->header('X-Tenant-Id');
    }
}
