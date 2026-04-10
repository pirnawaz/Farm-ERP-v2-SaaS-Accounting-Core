<?php

namespace App\Services;

use App\Models\Agreement;
use App\Models\Harvest;
use Illuminate\Support\Collection;

/**
 * Read-only resolution of which agreements apply to a harvest and normalized terms.
 * Deterministic: same harvest + same DB rows → same output order and winners.
 */
class AgreementResolver
{
    public const BASIS_PERCENT = 'PERCENT';

    public const BASIS_RATIO = 'RATIO';

    public const BASIS_FIXED = 'FIXED';

    /**
     * @return array{
     *   machine_agreements: list<array<string, mixed>>,
     *   labour_agreements: list<array<string, mixed>>,
     *   landlord_agreements: list<array<string, mixed>>,
     * }
     */
    public function resolveForHarvest(Harvest $harvest): array
    {
        $candidates = $this->queryCandidates($harvest);

        $machine = $this->resolveBucket(
            $candidates->where('agreement_type', Agreement::TYPE_MACHINE_USAGE)->values(),
            fn (Agreement $a) => $a->machine_id !== null ? (string) $a->machine_id : null
        );

        $labour = $this->resolveBucket(
            $candidates->where('agreement_type', Agreement::TYPE_LABOUR)->values(),
            fn (Agreement $a) => $a->worker_id !== null ? (string) $a->worker_id : null
        );

        $landlord = $this->resolveBucket(
            $candidates->where('agreement_type', Agreement::TYPE_LAND_LEASE)->values(),
            fn (Agreement $a) => $a->party_id !== null ? (string) $a->party_id : null
        );

        return [
            'machine_agreements' => $machine,
            'labour_agreements' => $labour,
            'landlord_agreements' => $landlord,
        ];
    }

    /**
     * @return Collection<int, Agreement>
     */
    private function queryCandidates(Harvest $harvest): Collection
    {
        $d = $harvest->harvest_date?->format('Y-m-d');
        if ($d === null) {
            return collect();
        }

        return Agreement::query()
            ->where('tenant_id', $harvest->tenant_id)
            ->where('status', Agreement::STATUS_ACTIVE)
            ->whereIn('agreement_type', [
                Agreement::TYPE_MACHINE_USAGE,
                Agreement::TYPE_LABOUR,
                Agreement::TYPE_LAND_LEASE,
            ])
            ->where('effective_from', '<=', $d)
            ->where(function ($q) use ($d) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d);
            })
            ->where(function ($q) use ($harvest) {
                $q->whereNull('project_id')->orWhere('project_id', $harvest->project_id);
            })
            ->where(function ($q) use ($harvest) {
                $q->whereNull('crop_cycle_id')->orWhere('crop_cycle_id', $harvest->crop_cycle_id);
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Agreement $a) => $a->appliesToHarvest($harvest))
            ->values();
    }

    /**
     * One winner per group key (e.g. per machine_id). Highest priority, then most specific scope, then id.
     *
     * @param Collection<int, Agreement> $agreements
     * @param callable(Agreement): ?string $groupKey null skips row
     * @return list<array<string, mixed>>
     */
    private function resolveBucket(Collection $agreements, callable $groupKey): array
    {
        /** @var array<string, list<Agreement>> $buckets */
        $buckets = [];
        foreach ($agreements as $a) {
            $key = $groupKey($a);
            if ($key === null) {
                continue;
            }
            if (! isset($buckets[$key])) {
                $buckets[$key] = [];
            }
            $buckets[$key][] = $a;
        }

        ksort($buckets, SORT_STRING);

        $out = [];
        foreach ($buckets as $rows) {
            usort($rows, function (Agreement $a, Agreement $b): int {
                if ($a->priority !== $b->priority) {
                    return $b->priority <=> $a->priority;
                }
                $sa = $this->scopeSpecificity($a);
                $sb = $this->scopeSpecificity($b);
                if ($sa !== $sb) {
                    return $sb <=> $sa;
                }

                return strcmp((string) $a->id, (string) $b->id);
            });
            $winner = $rows[0];
            $out[] = $this->normalizedRow($winner);
        }

        return $out;
    }

    /**
     * More non-null scope fields => more specific (2 = project + crop, 1 = one of them, 0 = neither).
     */
    private function scopeSpecificity(Agreement $a): int
    {
        $n = 0;
        if ($a->project_id !== null) {
            ++$n;
        }
        if ($a->crop_cycle_id !== null) {
            ++$n;
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedRow(Agreement $a): array
    {
        $norm = $this->normalizeTerms($a);

        return [
            'agreement_id' => $a->id,
            'type' => $a->agreement_type,
            'machine_id' => $a->machine_id,
            'worker_id' => $a->worker_id,
            'party_id' => $a->party_id,
            'basis' => $norm['basis'],
            'value' => $norm['value'],
            'ratio_numerator' => $norm['ratio_numerator'],
            'ratio_denominator' => $norm['ratio_denominator'],
            'priority' => $a->priority,
            'specificity' => $this->scopeSpecificity($a),
        ];
    }

    /**
     * @return array{basis: string, value: ?string, ratio_numerator: ?string, ratio_denominator: ?string}
     */
    private function normalizeTerms(Agreement $a): array
    {
        $terms = is_array($a->terms) ? $a->terms : [];

        if (isset($terms['basis'])) {
            return $this->normalizeFromFlatBasis($terms);
        }
        foreach (['harvest_share', 'output_share', 'share'] as $k) {
            if (isset($terms[$k]) && is_array($terms[$k])) {
                return $this->normalizeFromFlatBasis($terms[$k]);
            }
        }
        if (isset($terms['pricing']) && is_array($terms['pricing'])) {
            return $this->normalizeFromPricing($terms['pricing']);
        }
        if (isset($terms['wage']) && is_array($terms['wage'])) {
            return $this->normalizeFromPricing($terms['wage']);
        }
        if (isset($terms['rent']) && is_array($terms['rent'])) {
            return $this->normalizeFromPricing($terms['rent']);
        }
        if (isset($terms['piece_rate']) && is_array($terms['piece_rate'])) {
            $p = $terms['piece_rate'];

            return [
                'basis' => self::BASIS_FIXED,
                'value' => isset($p['amount']) ? (string) $p['amount'] : null,
                'ratio_numerator' => null,
                'ratio_denominator' => null,
            ];
        }

        return [
            'basis' => 'UNKNOWN',
            'value' => null,
            'ratio_numerator' => null,
            'ratio_denominator' => null,
        ];
    }

    /**
     * @param array<string, mixed> $t
     *
     * @return array{basis: string, value: ?string, ratio_numerator: ?string, ratio_denominator: ?string}
     */
    private function normalizeFromFlatBasis(array $t): array
    {
        $basis = strtoupper((string) ($t['basis'] ?? ''));

        if ($basis === 'PERCENT' || $basis === 'PCT') {
            $v = $t['percent'] ?? $t['value'] ?? null;

            return [
                'basis' => self::BASIS_PERCENT,
                'value' => $v !== null ? (string) $v : null,
                'ratio_numerator' => null,
                'ratio_denominator' => null,
            ];
        }
        if ($basis === 'RATIO' || $basis === 'RATIO_SHARE') {
            return [
                'basis' => self::BASIS_RATIO,
                'value' => null,
                'ratio_numerator' => isset($t['numerator']) ? (string) $t['numerator'] : (isset($t['ratio_numerator']) ? (string) $t['ratio_numerator'] : null),
                'ratio_denominator' => isset($t['denominator']) ? (string) $t['denominator'] : (isset($t['ratio_denominator']) ? (string) $t['ratio_denominator'] : null),
            ];
        }
        if (in_array($basis, ['FIXED_QTY', 'FIXED', 'FIXED_RATE', 'QTY'], true)) {
            $v = $t['qty'] ?? $t['amount'] ?? $t['share_value'] ?? $t['value'] ?? null;

            return [
                'basis' => self::BASIS_FIXED,
                'value' => $v !== null ? (string) $v : null,
                'ratio_numerator' => null,
                'ratio_denominator' => null,
            ];
        }

        return [
            'basis' => 'UNKNOWN',
            'value' => null,
            'ratio_numerator' => null,
            'ratio_denominator' => null,
        ];
    }

    /**
     * @param array<string, mixed> $p
     *
     * @return array{basis: string, value: ?string, ratio_numerator: ?string, ratio_denominator: ?string}
     */
    private function normalizeFromPricing(array $p): array
    {
        $inner = isset($p['basis']) ? $p : array_merge($p, ['basis' => $p['pricing_model'] ?? 'FIXED_RATE']);

        return $this->normalizeFromFlatBasis($inner);
    }
}
