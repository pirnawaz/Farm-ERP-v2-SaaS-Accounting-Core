<?php

namespace App\Services;

use App\Models\Agreement;
use Illuminate\Validation\ValidationException;

/**
 * Validates canonical settlement terms under Agreement.terms (terms.settlement or terms.profit_distribution).
 * Used on agreement write paths when a land agreement is project-scoped and active (drives project settlement).
 */
class AgreementSettlementTermsValidator
{
    public function __construct(
        private ProjectSettlementRuleResolver $resolver
    ) {}

    /**
     * Whether this agreement row (merged final state) requires a parseable settlement block.
     */
    public function requiresSettlementTerms(array $data): bool
    {
        return ($data['agreement_type'] ?? null) === Agreement::TYPE_LAND_LEASE
            && ! empty($data['project_id'])
            && ($data['status'] ?? Agreement::STATUS_ACTIVE) === Agreement::STATUS_ACTIVE;
    }

    /**
     * @param array<string, mixed>|null $terms
     *
     * @throws ValidationException
     */
    public function assertParseableSettlementTerms(?array $terms): void
    {
        $parsed = $this->resolver->parseSettlementFromAgreementTerms(is_array($terms) ? $terms : []);
        if ($parsed === null) {
            throw ValidationException::withMessages([
                'terms' => [
                    'Active project-scoped land agreements must include a parseable terms.settlement block with profit_split_landlord_pct and profit_split_hari_pct (and optional kamdari_pct, kamdar_party_id).',
                ],
            ]);
        }

        $landlord = (float) $parsed['profit_split_landlord_pct'];
        $hari = (float) $parsed['profit_split_hari_pct'];
        if ($landlord < 0 || $landlord > 100 || $hari < 0 || $hari > 100) {
            throw ValidationException::withMessages([
                'terms' => ['Landlord and operator profit split percentages must be between 0 and 100.'],
            ]);
        }
        if (abs($landlord + $hari - 100.0) > 0.02) {
            throw ValidationException::withMessages([
                'terms' => ['profit_split_landlord_pct and profit_split_hari_pct must sum to 100.'],
            ]);
        }

        $k = (float) $parsed['kamdari_pct'];
        if ($k < 0 || $k > 100) {
            throw ValidationException::withMessages([
                'terms' => ['kamdari_pct must be between 0 and 100.'],
            ]);
        }
        if ($k > 0 && empty($parsed['kamdar_party_id'])) {
            throw ValidationException::withMessages([
                'terms' => ['kamdar_party_id is required when kamdari_pct is greater than zero.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertLandAgreementSupportsProjectSettlement(?Agreement $agreement): void
    {
        if ($agreement === null || $agreement->agreement_type !== Agreement::TYPE_LAND_LEASE) {
            return;
        }
        $merged = [
            'agreement_type' => $agreement->agreement_type,
            'project_id' => $agreement->project_id,
            'status' => $agreement->status ?? Agreement::STATUS_ACTIVE,
        ];
        if (! $this->requiresSettlementTerms($merged)) {
            return;
        }
        $this->assertParseableSettlementTerms(is_array($agreement->terms) ? $agreement->terms : null);
    }
}
