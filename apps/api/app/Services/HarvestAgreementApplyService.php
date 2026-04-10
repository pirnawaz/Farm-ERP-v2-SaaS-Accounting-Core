<?php

namespace App\Services;

use App\Models\Harvest;
use App\Models\HarvestShareLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Explicit draft-only application of resolved agreements as harvest share lines (no posting).
 */
class HarvestAgreementApplyService
{
    public function __construct(
        private HarvestService $harvestService,
        private SuggestionService $suggestionService
    ) {}

    /**
     * Creates share lines from active agreement terms (via SuggestionService + AgreementResolver).
     * Only rows driven by AGREEMENT sources are materialized; pure field-job ratios are not auto-created.
     *
     * @return array{harvest: Harvest, created_count: int, replaced_existing: bool, message: string|null}
     */
    public function applyToDraft(Harvest $harvest, bool $overwrite): array
    {
        if (! $harvest->isDraft()) {
            throw ValidationException::withMessages([
                'harvest' => ['Agreements can only be applied while the harvest is DRAFT.'],
            ]);
        }

        $harvest->load(['lines.item', 'lines.store', 'shareLines']);

        $existingCount = $harvest->shareLines->count();
        if ($existingCount > 0 && ! $overwrite) {
            throw ValidationException::withMessages([
                'overwrite' => ['Share lines already exist. Resubmit with "overwrite": true to replace them.'],
            ]);
        }

        $suggestions = $this->suggestionService->forHarvest($harvest);
        $payloads = $this->buildShareLinePayloads($harvest, $suggestions);

        $harvestId = $harvest->id;
        $tenantId = $harvest->tenant_id;

        return DB::transaction(function () use ($harvest, $harvestId, $tenantId, $overwrite, $existingCount, $payloads) {
            $replaced = false;
            if ($overwrite && $existingCount > 0) {
                $replaced = true;
                foreach ($harvest->shareLines as $line) {
                    $this->harvestService->deleteShareLine($line);
                }
            }

            $created = 0;
            if ($payloads === []) {
                return [
                    'harvest' => Harvest::where('id', $harvestId)->where('tenant_id', $tenantId)
                        ->with(Harvest::detailWithRelations())
                        ->firstOrFail(),
                    'created_count' => 0,
                    'replaced_existing' => $replaced,
                    'message' => 'No applicable agreement terms to apply.',
                ];
            }

            $last = Harvest::where('id', $harvestId)->where('tenant_id', $tenantId)->firstOrFail();
            foreach ($payloads as $data) {
                $last = $this->harvestService->addShareLine($last, $data);
                ++$created;
            }

            return [
                'harvest' => $last,
                'created_count' => $created,
                'replaced_existing' => $replaced,
                'message' => null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $suggestions
     * @return list<array<string, mixed>>
     */
    private function buildShareLinePayloads(Harvest $harvest, array $suggestions): array
    {
        $defaultLine = $harvest->lines->sortBy('id')->first();
        $harvestLineId = $defaultLine?->id;
        $inventoryItemId = $defaultLine?->inventory_item_id;
        $storeId = $defaultLine?->store_id;

        $out = [];

        $machineRows = array_values(array_filter(
            $suggestions['machine_suggestions'] ?? [],
            fn (array $r) => ($r['suggestion_source'] ?? null) === SuggestionService::SOURCE_AGREEMENT
        ));
        usort($machineRows, fn (array $a, array $b) => strcmp((string) ($a['machine_id'] ?? ''), (string) ($b['machine_id'] ?? '')));

        foreach ($machineRows as $row) {
            $agreementId = $row['agreement_id'] ?? null;
            $out[] = $this->basePayloadFromDefaults($harvestLineId, $inventoryItemId, $storeId, [
                'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
                'share_basis' => $row['suggested_share_basis'],
                'share_value' => $this->numericOrNull($row['suggested_share_value'] ?? null),
                'ratio_numerator' => $this->numericOrNull($row['suggested_ratio_numerator'] ?? null),
                'ratio_denominator' => $this->numericOrNull($row['suggested_ratio_denominator'] ?? null),
                'machine_id' => $row['machine_id'],
                'source_field_job_id' => $row['suggested_source_field_job_id'] ?? null,
                'notes' => $agreementId ? 'Applied from agreement '.$agreementId.'.' : null,
                'rule_snapshot' => $this->ruleSnapshot('agreement', $agreementId, $row['suggestion_source'] ?? null),
            ]);
        }

        $labourRows = array_values(array_filter(
            $suggestions['labour_suggestions'] ?? [],
            fn (array $r) => ($r['suggestion_source'] ?? null) === SuggestionService::SOURCE_AGREEMENT
        ));
        usort($labourRows, fn (array $a, array $b) => strcmp((string) ($a['worker_id'] ?? ''), (string) ($b['worker_id'] ?? '')));

        foreach ($labourRows as $row) {
            $agreementId = $row['agreement_id'] ?? null;
            $out[] = $this->basePayloadFromDefaults($harvestLineId, $inventoryItemId, $storeId, [
                'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
                'share_basis' => $row['suggested_share_basis'],
                'share_value' => $this->numericOrNull($row['suggested_share_value'] ?? null),
                'ratio_numerator' => $this->numericOrNull($row['suggested_ratio_numerator'] ?? null),
                'ratio_denominator' => $this->numericOrNull($row['suggested_ratio_denominator'] ?? null),
                'worker_id' => $row['worker_id'],
                'source_field_job_id' => $row['suggested_source_field_job_id'] ?? null,
                'notes' => $agreementId ? 'Applied from agreement '.$agreementId.'.' : null,
                'rule_snapshot' => $this->ruleSnapshot('agreement', $agreementId, $row['suggestion_source'] ?? null),
            ]);
        }

        $tpl = $suggestions['share_templates'][0] ?? null;
        if ($tpl !== null && ($tpl['template_source'] ?? null) === SuggestionService::SOURCE_AGREEMENT) {
            foreach ($tpl['lines'] ?? [] as $line) {
                if (($line['suggestion_source'] ?? null) !== SuggestionService::SOURCE_AGREEMENT) {
                    continue;
                }
                $agreementId = $line['agreement_id'] ?? null;
                $out[] = $this->basePayloadFromDefaults($harvestLineId, $inventoryItemId, $storeId, [
                    'recipient_role' => $line['recipient_role'],
                    'settlement_mode' => $line['settlement_mode'],
                    'share_basis' => $line['share_basis'],
                    'share_value' => $this->numericOrNull($line['share_value'] ?? null),
                    'ratio_numerator' => $this->numericOrNull($line['ratio_numerator'] ?? null),
                    'ratio_denominator' => $this->numericOrNull($line['ratio_denominator'] ?? null),
                    'remainder_bucket' => (bool) ($line['remainder_bucket'] ?? false),
                    'beneficiary_party_id' => $line['beneficiary_party_id'] ?? null,
                    'machine_id' => $line['machine_id'] ?? null,
                    'worker_id' => $line['worker_id'] ?? null,
                    'sort_order' => $line['sort_order'] ?? null,
                    'notes' => $agreementId ? 'Applied from agreement '.$agreementId.'.' : ($line['notes'] ?? null),
                    'rule_snapshot' => $this->ruleSnapshot('agreement', $agreementId, SuggestionService::SOURCE_AGREEMENT),
                ]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function basePayloadFromDefaults(
        ?string $harvestLineId,
        ?string $inventoryItemId,
        ?string $storeId,
        array $fields
    ): array {
        return array_merge([
            'harvest_line_id' => $harvestLineId,
            'inventory_item_id' => $inventoryItemId,
            'store_id' => $storeId,
        ], $fields);
    }

    private function numericOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (float) $v;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ruleSnapshot(string $kind, ?string $agreementId, ?string $source): ?array
    {
        if ($agreementId === null && $source === null) {
            return null;
        }

        return array_filter([
            'apply_via' => 'POST_APPLY_AGREEMENTS',
            'kind' => $kind,
            'agreement_id' => $agreementId,
            'suggestion_source' => $source,
        ]);
    }
}
