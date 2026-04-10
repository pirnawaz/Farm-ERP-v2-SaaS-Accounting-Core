<?php

namespace App\Services;

use App\Models\FieldJob;
use App\Models\Harvest;
use App\Models\HarvestShareLine;
use App\Models\LabWorker;
use App\Models\Machine;
use Illuminate\Support\Collection;

/**
 * Read-only harvest workflow suggestions (no posting, no DB writes).
 *
 * Machine/labour rows derive from field jobs linked by project/crop (and optional explicit share-line links).
 * Share ratios allocate each line's usage/units against the total pool of all lines in that pool (deterministic).
 * Active agreements (via AgreementResolver) override usage-derived ratios when terms are known; otherwise
 * behaviour matches historical field-job-only suggestions.
 */
class SuggestionService
{
    public const CONFIDENCE_HIGH = 'HIGH';

    public const CONFIDENCE_MEDIUM = 'MEDIUM';

    public const CONFIDENCE_LOW = 'LOW';

    public const SOURCE_AGREEMENT = 'AGREEMENT';

    public const SOURCE_HISTORY = 'HISTORY';

    public const SOURCE_FIELD_JOB = 'FIELD_JOB';

    public function __construct(
        private AgreementResolver $agreementResolver
    ) {}

    /**
     * @return array{
     *   machine_suggestions: list<array<string, mixed>>,
     *   labour_suggestions: list<array<string, mixed>>,
     *   share_templates: list<array<string, mixed>>,
     *   confidence: self::CONFIDENCE_*
     * }
     */
    public function forHarvest(Harvest $harvest): array
    {
        $harvest->loadMissing(['shareLines']);

        $resolved = $this->agreementResolver->resolveForHarvest($harvest);
        $machineAgreementsById = $this->indexAgreementsByKey($resolved['machine_agreements'], 'machine_id');
        $labourAgreementsById = $this->indexAgreementsByKey($resolved['labour_agreements'], 'worker_id');

        $fieldJobs = $this->resolveLinkedFieldJobs($harvest);

        $machineLines = $this->collectMachineLines($fieldJobs);
        $machineTotal = $this->sumUsages($machineLines->pluck('usage_qty'));

        $labourLines = $this->collectLabourLines($fieldJobs);
        $labourTotal = $this->sumUsages($labourLines->pluck('units'));

        $machineSuggestions = $this->buildMachineSuggestionsMerged(
            $harvest,
            $machineLines,
            $machineTotal,
            $machineAgreementsById
        );
        $labourSuggestions = $this->buildLabourSuggestionsMerged(
            $harvest,
            $labourLines,
            $labourTotal,
            $labourAgreementsById
        );

        $shareTemplates = $this->resolveShareTemplates($harvest, $resolved['landlord_agreements']);

        $confidence = $this->resolveConfidenceMerged(
            $machineSuggestions,
            $labourSuggestions,
            $shareTemplates
        );

        return [
            'machine_suggestions' => $machineSuggestions,
            'labour_suggestions' => $labourSuggestions,
            'share_templates' => $shareTemplates,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexAgreementsByKey(array $rows, string $idField): array
    {
        $map = [];
        foreach ($rows as $r) {
            if (! empty($r[$idField])) {
                $map[(string) $r[$idField]] = $r;
            }
        }

        return $map;
    }

    /**
     * @param Collection<int, object{line: \App\Models\FieldJobMachine, field_job_id: string, usage_qty: string}> $machineLines
     * @param array<string, array<string, mixed>> $agreementsByMachineId
     *
     * @return list<array<string, mixed>>
     */
    private function buildMachineSuggestionsMerged(
        Harvest $harvest,
        Collection $machineLines,
        string $machineTotal,
        array $agreementsByMachineId
    ): array {
        $seenMachinesWithFieldJob = [];
        $out = [];

        foreach ($machineLines as $row) {
            $machineId = (string) $row->line->machine_id;
            $seenMachinesWithFieldJob[$machineId] = true;

            $base = $this->buildMachineSuggestionFromFieldJobRow($row, $machineTotal);
            $ag = $agreementsByMachineId[$machineId] ?? null;
            $terms = $ag !== null ? $this->shareTermsFromAgreementRow($ag) : null;

            if ($ag !== null && $terms !== null) {
                $out[] = array_merge($base, $terms, [
                    'suggestion_source' => self::SOURCE_AGREEMENT,
                    'confidence' => self::CONFIDENCE_HIGH,
                    'agreement_id' => $ag['agreement_id'],
                    'field_job_usage_matches_agreement' => true,
                ]);
            } else {
                $out[] = array_merge($base, [
                    'suggestion_source' => self::SOURCE_FIELD_JOB,
                    'confidence' => self::CONFIDENCE_MEDIUM,
                ]);
            }
        }

        foreach ($agreementsByMachineId as $machineId => $ag) {
            if (isset($seenMachinesWithFieldJob[$machineId])) {
                continue;
            }
            $terms = $this->shareTermsFromAgreementRow($ag);
            if ($terms === null) {
                continue;
            }
            $out[] = $this->buildMachineSuggestionAgreementOnly($harvest, $ag, $machineTotal, $terms);
        }

        usort($out, $this->machineSuggestionSort(...));

        return $out;
    }

    /**
     * @param array<string, mixed> $ag
     *
     * @return array<string, mixed>|null
     */
    private function buildMachineSuggestionAgreementOnly(
        Harvest $harvest,
        array $ag,
        string $machineTotal,
        array $terms
    ): array {
        $m = Machine::query()
            ->where('tenant_id', $harvest->tenant_id)
            ->where('id', $ag['machine_id'])
            ->first();

        return array_merge([
            'field_job_id' => null,
            'field_job_machine_id' => null,
            'machine_id' => $ag['machine_id'],
            'machine_code' => $m?->code,
            'machine_name' => $m?->name,
            'usage_qty' => null,
            'meter_unit_snapshot' => null,
            'pool_total_usage' => $machineTotal,
            'suggested_recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'suggested_settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'suggested_source_field_job_id' => null,
        ], $terms, [
            'suggestion_source' => self::SOURCE_AGREEMENT,
            'confidence' => self::CONFIDENCE_HIGH,
            'agreement_id' => $ag['agreement_id'],
            'field_job_usage_matches_agreement' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function machineSuggestionSort(array $a, array $b): int
    {
        $cmp = strcmp((string) ($a['machine_id'] ?? ''), (string) ($b['machine_id'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) ($a['field_job_machine_id'] ?? ''), (string) ($b['field_job_machine_id'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['agreement_id'] ?? ''), (string) ($b['agreement_id'] ?? ''));
    }

    /**
     * @param Collection<int, object{line: \App\Models\FieldJobLabour, field_job_id: string, units: string}> $labourLines
     * @param array<string, array<string, mixed>> $agreementsByWorkerId
     *
     * @return list<array<string, mixed>>
     */
    private function buildLabourSuggestionsMerged(
        Harvest $harvest,
        Collection $labourLines,
        string $labourTotal,
        array $agreementsByWorkerId
    ): array {
        $seenWorkersWithFieldJob = [];
        $out = [];

        foreach ($labourLines as $row) {
            $workerId = (string) $row->line->worker_id;
            $seenWorkersWithFieldJob[$workerId] = true;

            $base = $this->buildLabourSuggestionFromFieldJobRow($row, $labourTotal);
            $ag = $agreementsByWorkerId[$workerId] ?? null;
            $terms = $ag !== null ? $this->shareTermsFromAgreementRow($ag) : null;

            if ($ag !== null && $terms !== null) {
                $out[] = array_merge($base, $terms, [
                    'suggestion_source' => self::SOURCE_AGREEMENT,
                    'confidence' => self::CONFIDENCE_HIGH,
                    'agreement_id' => $ag['agreement_id'],
                    'field_job_usage_matches_agreement' => true,
                ]);
            } else {
                $out[] = array_merge($base, [
                    'suggestion_source' => self::SOURCE_FIELD_JOB,
                    'confidence' => self::CONFIDENCE_MEDIUM,
                ]);
            }
        }

        foreach ($agreementsByWorkerId as $workerId => $ag) {
            if (isset($seenWorkersWithFieldJob[$workerId])) {
                continue;
            }
            $terms = $this->shareTermsFromAgreementRow($ag);
            if ($terms === null) {
                continue;
            }
            $out[] = $this->buildLabourSuggestionAgreementOnly($harvest, $ag, $labourTotal, $terms);
        }

        usort($out, $this->labourSuggestionSort(...));

        return $out;
    }

    /**
     * @param array<string, mixed> $ag
     *
     * @return array<string, mixed>
     */
    private function buildLabourSuggestionAgreementOnly(
        Harvest $harvest,
        array $ag,
        string $labourTotal,
        array $terms
    ): array {
        $worker = null;
        if (! empty($ag['worker_id'])) {
            $worker = LabWorker::query()
                ->where('tenant_id', $harvest->tenant_id)
                ->where('id', $ag['worker_id'])
                ->first();
        }

        return array_merge([
            'field_job_id' => null,
            'field_job_labour_id' => null,
            'worker_id' => $ag['worker_id'],
            'worker_name' => $worker?->name,
            'units' => null,
            'rate_basis' => null,
            'pool_total_units' => $labourTotal,
            'suggested_recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'suggested_settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'suggested_source_field_job_id' => null,
        ], $terms, [
            'suggestion_source' => self::SOURCE_AGREEMENT,
            'confidence' => self::CONFIDENCE_HIGH,
            'agreement_id' => $ag['agreement_id'],
            'field_job_usage_matches_agreement' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function labourSuggestionSort(array $a, array $b): int
    {
        $cmp = strcmp((string) ($a['worker_id'] ?? ''), (string) ($b['worker_id'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) ($a['field_job_labour_id'] ?? ''), (string) ($b['field_job_labour_id'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['agreement_id'] ?? ''), (string) ($b['agreement_id'] ?? ''));
    }

    /**
     * Map normalized agreement terms to harvest share line suggestion fields.
     *
     * @param array<string, mixed> $ag
     *
     * @return array<string, mixed>|null null when basis is unknown — caller falls back to field-job history
     */
    private function shareTermsFromAgreementRow(array $ag): ?array
    {
        $basis = (string) ($ag['basis'] ?? '');

        if ($basis === AgreementResolver::BASIS_PERCENT) {
            return [
                'suggested_share_basis' => HarvestShareLine::BASIS_PERCENT,
                'suggested_share_value' => isset($ag['value']) ? (string) $ag['value'] : null,
                'suggested_ratio_numerator' => null,
                'suggested_ratio_denominator' => null,
            ];
        }

        if ($basis === AgreementResolver::BASIS_RATIO) {
            return [
                'suggested_share_basis' => HarvestShareLine::BASIS_RATIO,
                'suggested_share_value' => null,
                'suggested_ratio_numerator' => isset($ag['ratio_numerator']) ? (string) $ag['ratio_numerator'] : null,
                'suggested_ratio_denominator' => isset($ag['ratio_denominator']) ? (string) $ag['ratio_denominator'] : null,
            ];
        }

        if ($basis === AgreementResolver::BASIS_FIXED) {
            return [
                'suggested_share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
                'suggested_share_value' => isset($ag['value']) ? (string) $ag['value'] : null,
                'suggested_ratio_numerator' => null,
                'suggested_ratio_denominator' => null,
            ];
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $landlordAgreements
     *
     * @return list<array<string, mixed>>
     */
    private function resolveShareTemplates(Harvest $harvest, array $landlordAgreements): array
    {
        $fromAgreements = $this->buildShareTemplateFromLandlordAgreements($landlordAgreements);
        if ($fromAgreements !== null) {
            return [$fromAgreements];
        }

        $previousHarvest = $this->findPreviousPostedHarvest($harvest);
        if ($previousHarvest !== null) {
            return [$this->buildShareTemplateFromHarvest($previousHarvest)];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $landlordAgreements
     */
    private function buildShareTemplateFromLandlordAgreements(array $landlordAgreements): ?array
    {
        if ($landlordAgreements === []) {
            return null;
        }

        usort($landlordAgreements, function (array $a, array $b): int {
            $c = strcmp((string) ($a['party_id'] ?? ''), (string) ($b['party_id'] ?? ''));
            if ($c !== 0) {
                return $c;
            }

            return strcmp((string) ($a['agreement_id'] ?? ''), (string) ($b['agreement_id'] ?? ''));
        });

        $lines = [];
        $sort = 0;
        foreach ($landlordAgreements as $ag) {
            $terms = $this->shareTermsFromAgreementRow($ag);
            if ($terms === null || empty($ag['party_id'])) {
                continue;
            }
            $lines[] = [
                'recipient_role' => HarvestShareLine::RECIPIENT_LANDLORD,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
                'share_basis' => $terms['suggested_share_basis'],
                'share_value' => $terms['suggested_share_value'] ?? null,
                'ratio_numerator' => $terms['suggested_ratio_numerator'] ?? null,
                'ratio_denominator' => $terms['suggested_ratio_denominator'] ?? null,
                'remainder_bucket' => false,
                'beneficiary_party_id' => $ag['party_id'],
                'machine_id' => null,
                'worker_id' => null,
                'sort_order' => $sort,
                'notes' => null,
                'suggestion_source' => self::SOURCE_AGREEMENT,
                'agreement_id' => $ag['agreement_id'],
            ];
            ++$sort;
        }

        if ($lines === []) {
            return null;
        }

        return [
            'template_source' => self::SOURCE_AGREEMENT,
            'source_harvest_id' => null,
            'source_harvest_no' => null,
            'source_harvest_date' => null,
            'lines' => $lines,
        ];
    }

    /**
     * @param list<array<string, mixed>> $machineSuggestions
     * @param list<array<string, mixed>> $labourSuggestions
     * @param list<array<string, mixed>> $shareTemplates
     */
    private function resolveConfidenceMerged(
        array $machineSuggestions,
        array $labourSuggestions,
        array $shareTemplates
    ): string {
        foreach ($machineSuggestions as $row) {
            if (($row['suggestion_source'] ?? null) === self::SOURCE_AGREEMENT) {
                return self::CONFIDENCE_HIGH;
            }
        }
        foreach ($labourSuggestions as $row) {
            if (($row['suggestion_source'] ?? null) === self::SOURCE_AGREEMENT) {
                return self::CONFIDENCE_HIGH;
            }
        }
        $tpl = $shareTemplates[0] ?? null;
        if ($tpl !== null && ($tpl['template_source'] ?? null) === self::SOURCE_AGREEMENT) {
            return self::CONFIDENCE_HIGH;
        }

        return $this->resolveConfidence($machineSuggestions, $labourSuggestions, $shareTemplates);
    }

    /**
     * Field jobs: same tenant, project, crop cycle; job on/before harvest date; not reversed.
     * Union explicit source_field_job_id links on this harvest's share lines.
     *
     * @return Collection<int, FieldJob>
     */
    private function resolveLinkedFieldJobs(Harvest $harvest): Collection
    {
        $tenantId = $harvest->tenant_id;

        $scopedIds = FieldJob::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $harvest->project_id)
            ->where('crop_cycle_id', $harvest->crop_cycle_id)
            ->whereNull('reversed_at')
            ->whereIn('status', ['DRAFT', 'POSTED'])
            ->where('job_date', '<=', $harvest->harvest_date)
            ->pluck('id');

        $explicitIds = $harvest->shareLines
            ->pluck('source_field_job_id')
            ->filter()
            ->unique()
            ->values();

        $allIds = $scopedIds->merge($explicitIds)->unique()->values();
        if ($allIds->isEmpty()) {
            return collect();
        }

        return FieldJob::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $allIds)
            ->with([
                'machines' => fn ($q) => $q->orderBy('id'),
                'machines.machine',
                'labour' => fn ($q) => $q->orderBy('id'),
                'labour.worker',
            ])
            ->get()
            ->sortBy([
                ['job_date', 'desc'],
                ['id', 'asc'],
            ])
            ->values();
    }

    /**
     * @param Collection<int, FieldJob> $fieldJobs
     * @return Collection<int, object{line: \App\Models\FieldJobMachine, field_job_id: string, usage_qty: string}>
     */
    private function collectMachineLines(Collection $fieldJobs): Collection
    {
        $rows = collect();
        foreach ($fieldJobs as $job) {
            foreach ($job->machines as $m) {
                $rows->push((object) [
                    'line' => $m,
                    'field_job_id' => $job->id,
                    'usage_qty' => (string) ($m->usage_qty ?? '0'),
                ]);
            }
        }

        return $rows->sortBy(fn ($r) => [$r->field_job_id, $r->line->id])->values();
    }

    /**
     * @param Collection<int, FieldJob> $fieldJobs
     * @return Collection<int, object{line: \App\Models\FieldJobLabour, field_job_id: string, units: string}>
     */
    private function collectLabourLines(Collection $fieldJobs): Collection
    {
        $rows = collect();
        foreach ($fieldJobs as $job) {
            foreach ($job->labour as $l) {
                $rows->push((object) [
                    'line' => $l,
                    'field_job_id' => $job->id,
                    'units' => (string) ($l->units ?? '0'),
                ]);
            }
        }

        return $rows->sortBy(fn ($r) => [$r->field_job_id, $r->line->id])->values();
    }

    /**
     * @param Collection<int, string> $amounts
     */
    private function sumUsages(Collection $amounts): string
    {
        $sum = '0';
        foreach ($amounts as $a) {
            $sum = $this->bcAdd($sum, (string) $a);
        }

        return $sum;
    }

    /**
     * @param object{line: \App\Models\FieldJobMachine, field_job_id: string, usage_qty: string} $row
     *
     * @return array<string, mixed>
     */
    private function buildMachineSuggestionFromFieldJobRow(object $row, string $machineTotal): array
    {
        $m = $row->line;
        $usage = $row->usage_qty;
        $machine = $m->machine;
        $ratio = $this->ratioParts($usage, $machineTotal);

        return [
            'field_job_id' => $row->field_job_id,
            'field_job_machine_id' => $m->id,
            'machine_id' => $m->machine_id,
            'machine_code' => $machine?->code,
            'machine_name' => $machine?->name,
            'usage_qty' => $usage,
            'meter_unit_snapshot' => $m->meter_unit_snapshot,
            'pool_total_usage' => $machineTotal,
            'suggested_recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'suggested_settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'suggested_share_basis' => HarvestShareLine::BASIS_RATIO,
            'suggested_share_value' => null,
            'suggested_ratio_numerator' => $ratio['numerator'],
            'suggested_ratio_denominator' => $ratio['denominator'],
            'suggested_source_field_job_id' => $row->field_job_id,
        ];
    }

    /**
     * @param object{line: \App\Models\FieldJobLabour, field_job_id: string, units: string} $row
     *
     * @return array<string, mixed>
     */
    private function buildLabourSuggestionFromFieldJobRow(object $row, string $labourTotal): array
    {
        $l = $row->line;
        $units = $row->units;
        $worker = $l->worker;
        $ratio = $this->ratioParts($units, $labourTotal);

        return [
            'field_job_id' => $row->field_job_id,
            'field_job_labour_id' => $l->id,
            'worker_id' => $l->worker_id,
            'worker_name' => $worker?->name,
            'units' => $units,
            'rate_basis' => $l->rate_basis,
            'pool_total_units' => $labourTotal,
            'suggested_recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'suggested_settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'suggested_share_basis' => HarvestShareLine::BASIS_RATIO,
            'suggested_share_value' => null,
            'suggested_ratio_numerator' => $ratio['numerator'],
            'suggested_ratio_denominator' => $ratio['denominator'],
            'suggested_source_field_job_id' => $row->field_job_id,
        ];
    }

    private function findPreviousPostedHarvest(Harvest $harvest): ?Harvest
    {
        return Harvest::query()
            ->where('tenant_id', $harvest->tenant_id)
            ->where('project_id', $harvest->project_id)
            ->where('crop_cycle_id', $harvest->crop_cycle_id)
            ->where('id', '!=', $harvest->id)
            ->where('status', 'POSTED')
            ->where(function ($q) use ($harvest) {
                $q->where('harvest_date', '<', $harvest->harvest_date)
                    ->orWhere(function ($q2) use ($harvest) {
                        $q2->whereDate('harvest_date', '=', $harvest->harvest_date->format('Y-m-d'))
                            ->where('id', '<', $harvest->id);
                    });
            })
            ->orderBy('harvest_date', 'desc')
            ->orderBy('id', 'desc')
            ->with(['shareLines' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->first();
    }

    /**
     * @return array{template_source: self::SOURCE_HISTORY, source_harvest_id: string, source_harvest_no: string|null, source_harvest_date: string|null, lines: list<array<string, mixed>>}
     */
    private function buildShareTemplateFromHarvest(Harvest $h): array
    {
        $lines = [];
        foreach ($h->shareLines as $sl) {
            $lines[] = $this->shareLineToTemplate($sl);
        }

        return [
            'template_source' => self::SOURCE_HISTORY,
            'source_harvest_id' => $h->id,
            'source_harvest_no' => $h->harvest_no,
            'source_harvest_date' => $h->harvest_date?->format('Y-m-d'),
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shareLineToTemplate(HarvestShareLine $line): array
    {
        return [
            'recipient_role' => $line->recipient_role,
            'settlement_mode' => $line->settlement_mode,
            'share_basis' => $line->share_basis,
            'share_value' => $line->share_value !== null ? (string) $line->share_value : null,
            'ratio_numerator' => $line->ratio_numerator !== null ? (string) $line->ratio_numerator : null,
            'ratio_denominator' => $line->ratio_denominator !== null ? (string) $line->ratio_denominator : null,
            'remainder_bucket' => (bool) $line->remainder_bucket,
            'beneficiary_party_id' => $line->beneficiary_party_id,
            'machine_id' => $line->machine_id,
            'worker_id' => $line->worker_id,
            'sort_order' => $line->sort_order,
            'notes' => $line->notes,
            'suggestion_source' => self::SOURCE_HISTORY,
        ];
    }

    /**
     * @param list<array<string, mixed>> $machineSuggestions
     * @param list<array<string, mixed>> $labourSuggestions
     * @param list<array<string, mixed>> $shareTemplates
     */
    private function resolveConfidence(array $machineSuggestions, array $labourSuggestions, array $shareTemplates): string
    {
        $hasFj = $machineSuggestions !== [] || $labourSuggestions !== [];
        $hasTpl = $shareTemplates !== [] && ($shareTemplates[0]['lines'] ?? []) !== [];

        if ($hasFj && $hasTpl) {
            return self::CONFIDENCE_HIGH;
        }
        if ($hasFj || $hasTpl) {
            return self::CONFIDENCE_MEDIUM;
        }

        return self::CONFIDENCE_LOW;
    }

    /**
     * @return array{numerator: string, denominator: string}
     */
    private function ratioParts(string $part, string $total): array
    {
        if ($this->bcComp($total, '0') <= 0) {
            return ['numerator' => '0', 'denominator' => '1'];
        }

        return ['numerator' => $part, 'denominator' => $total];
    }

    private function bcAdd(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 8);
        }

        return (string) ((float) $a + (float) $b);
    }

    private function bcComp(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, 8);
        }

        return (float) $a <=> (float) $b;
    }
}
