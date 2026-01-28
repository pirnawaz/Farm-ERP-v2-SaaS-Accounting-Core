<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRule;
use App\Http\Requests\UpdateProjectRulesRequest;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class ProjectRuleController extends Controller
{
    public function show(Request $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->with('party')
            ->firstOrFail();

        $rule = ProjectRule::where('project_id', $project->id)->first();

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
            ]);
        }

        return response()->json($rule);
    }

    public function update(UpdateProjectRulesRequest $request, string $id)
    {
        $tenantId = TenantContext::getTenantId($request);

        $project = Project::where('id', $id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

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
