<?php

namespace App\Services;

use App\Models\Agreement;
use App\Models\Project;
use App\Models\ProjectRule;

/**
 * Resolves profit-share / kamdari inputs for settlement and related flows.
 * Prefers {@see Agreement} terms when the project is linked and terms contain a settlement block;
 * otherwise falls back to {@see ProjectRule}.
 */
class ProjectSettlementRuleResolver
{
    /**
     * @return array{
     *   profit_split_landlord_pct: string,
     *   profit_split_hari_pct: string,
     *   kamdari_pct: string,
     *   kamdar_party_id: ?string,
     *   kamdari_order: ?string,
     *   pool_definition: mixed,
     *   resolution_source: 'agreement'|'project_rule',
     *   agreement_id: ?string,
     *   project_rule_id: ?string
     * }
     */
    public function resolveSettlementRule(Project $project): array
    {
        if ($project->agreement_id) {
            $agreement = Agreement::query()
                ->where('tenant_id', $project->tenant_id)
                ->where('id', $project->agreement_id)
                ->first();

            if ($agreement) {
                $parsed = $this->parseSettlementFromAgreementTerms(is_array($agreement->terms) ? $agreement->terms : []);
                if ($parsed !== null) {
                    $normalized = $this->normalizeParsedSettlement($parsed);

                    return array_merge($normalized, [
                        'resolution_source' => 'agreement',
                        'agreement_id' => $agreement->id,
                        'project_rule_id' => null,
                    ]);
                }

                $fallbackRule = ProjectRule::where('project_id', $project->id)->first();
                if ($fallbackRule) {
                    return $this->ruleFromProjectRule($fallbackRule);
                }

                throw new \RuntimeException(
                    'Cannot resolve settlement: this project is linked to an agreement without valid settlement terms (terms.settlement), and no project rules exist. Add settlement terms to the agreement or create project rules.'
                );
            }
        }

        $projectRule = ProjectRule::where('project_id', $project->id)->first();
        if (!$projectRule) {
            throw new \RuntimeException(
                'Cannot resolve settlement: no agreement settlement terms and no project rules exist for this project.'
            );
        }

        return $this->ruleFromProjectRule($projectRule);
    }

    /**
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private function normalizeParsedSettlement(array $parsed): array
    {
        return [
            'profit_split_landlord_pct' => number_format((float) $parsed['profit_split_landlord_pct'], 2, '.', ''),
            'profit_split_hari_pct' => number_format((float) $parsed['profit_split_hari_pct'], 2, '.', ''),
            'kamdari_pct' => number_format((float) $parsed['kamdari_pct'], 2, '.', ''),
            'kamdar_party_id' => $parsed['kamdar_party_id'],
            'kamdari_order' => $parsed['kamdari_order'],
            'pool_definition' => $parsed['pool_definition'],
        ];
    }

    /**
     * @return array{
     *   profit_split_landlord_pct: string,
     *   profit_split_hari_pct: string,
     *   kamdari_pct: string,
     *   kamdar_party_id: ?string,
     *   kamdari_order: ?string,
     *   pool_definition: mixed,
     *   resolution_source: 'project_rule',
     *   agreement_id: null,
     *   project_rule_id: string
     * }
     */
    private function ruleFromProjectRule(ProjectRule $projectRule): array
    {
        return [
            'profit_split_landlord_pct' => number_format((float) $projectRule->profit_split_landlord_pct, 2, '.', ''),
            'profit_split_hari_pct' => number_format((float) $projectRule->profit_split_hari_pct, 2, '.', ''),
            'kamdari_pct' => number_format((float) $projectRule->kamdari_pct, 2, '.', ''),
            'kamdar_party_id' => $projectRule->kamdar_party_id,
            'kamdari_order' => $projectRule->kamdari_order,
            'pool_definition' => $projectRule->pool_definition,
            'resolution_source' => 'project_rule',
            'agreement_id' => null,
            'project_rule_id' => $projectRule->id,
        ];
    }

    /**
     * @param array<string, mixed> $terms
     * @return array{
     *   profit_split_landlord_pct: string,
     *   profit_split_hari_pct: string,
     *   kamdari_pct: string,
     *   kamdar_party_id: ?string,
     *   kamdari_order: ?string,
     *   pool_definition: mixed
     * }|null
     */
    public function parseSettlementFromAgreementTerms(array $terms): ?array
    {
        $block = $terms['settlement'] ?? $terms['profit_distribution'] ?? null;
        if (!is_array($block)) {
            return null;
        }
        if (!array_key_exists('profit_split_landlord_pct', $block) || !array_key_exists('profit_split_hari_pct', $block)) {
            return null;
        }

        return [
            'profit_split_landlord_pct' => (string) $block['profit_split_landlord_pct'],
            'profit_split_hari_pct' => (string) $block['profit_split_hari_pct'],
            'kamdari_pct' => array_key_exists('kamdari_pct', $block) ? (string) $block['kamdari_pct'] : '0.00',
            'kamdar_party_id' => isset($block['kamdar_party_id']) ? (string) $block['kamdar_party_id'] : null,
            'kamdari_order' => isset($block['kamdari_order']) ? (string) $block['kamdari_order'] : 'BEFORE_SPLIT',
            'pool_definition' => $block['pool_definition'] ?? null,
        ];
    }
}
