<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRule;
use App\Http\Requests\UpdateProjectRulesRequest;
use App\Services\ProjectSettlementRuleResolver;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class ProjectRuleController extends Controller
{
    public function __construct(
        private ProjectSettlementRuleResolver $settlementRuleResolver
    ) {}

    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with(['party', 'agreement'])
            ->firstOrFail();

        $rule = ProjectRule::where('project_id', $project->id)->first();

        $meta = $this->settlementTermsMeta($project, $rule !== null);

        // Check if project is owner-operated (no HARI party)
        $isOwnerOperated = !$project->party || !in_array('HARI', $project->party->party_types ?? []);

        // Return default template if no rules exist
        if (!$rule) {
            return response()->json([
                'project_id' => $project->id,
                'profit_split_landlord_pct' => $isOwnerOperated ? 100.00 : 50.00,
                'profit_split_hari_pct' => $isOwnerOperated ? 0.00 : 50.00,
                'kamdari_pct' => 0.00,
                'kamdar_party_id' => null,
                'kamdari_order' => 'BEFORE_SPLIT',
                'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
                '_meta' => $meta,
            ]);
        }

        return response()->json(array_merge($rule->toArray(), [
            '_meta' => $meta,
        ]));
    }

    /**
     * @return array{
     *   settlement_terms_primary: 'agreement'|'project_rule'|'defaults_template',
     *   settlement_terms_hint: string
     * }
     */
    private function settlementTermsMeta(Project $project, bool $hasPersistedProjectRule): array
    {
        $agreement = $project->agreement;
        $hasAgreementSettlement = $agreement
            && $this->settlementRuleResolver->parseSettlementFromAgreementTerms(
                is_array($agreement->terms) ? $agreement->terms : []
            ) !== null;

        if ($hasAgreementSettlement) {
            return [
                'settlement_terms_primary' => 'agreement',
                'settlement_terms_hint' => 'Settlement terms are defined on the linked agreement. Project rules are legacy fallback only.',
            ];
        }

        if ($hasPersistedProjectRule) {
            return [
                'settlement_terms_primary' => 'project_rule',
                'settlement_terms_hint' => 'Settlement uses legacy project rules. Prefer defining terms on the agreement when the project is linked to one.',
            ];
        }

        return [
            'settlement_terms_primary' => 'defaults_template',
            'settlement_terms_hint' => 'These values are defaults only until you define settlement terms on an agreement or save project rules (legacy).',
        ];
    }

    public function update(UpdateProjectRulesRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($project->status === 'CLOSED') {
            return response()->json(['message' => 'Project is closed.'], 422);
        }

        $rule = ProjectRule::where('project_id', $project->id)->first();

        if ($rule) {
            $rule->update($request->validated());
        } else {
            $rule = ProjectRule::create([
                'project_id' => $project->id,
                ...$request->validated(),
            ]);
        }

        return response()->json($rule);
    }
}
