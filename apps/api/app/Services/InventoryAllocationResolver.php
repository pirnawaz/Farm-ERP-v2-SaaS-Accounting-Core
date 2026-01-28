<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\ShareRule;
use App\Models\ShareRuleLine;
use Carbon\Carbon;

class InventoryAllocationResolver
{
    public function __construct(
        private SystemPartyService $partyService
    ) {}

    /**
     * Resolve Hari Agreement shares for inventory inputs.
     * 
     * @param string $projectId
     * @param string $postingDate YYYY-MM-DD format
     * @param string|null $sharingRuleId
     * @param float|null $landlordPct Explicit landlord percentage
     * @param float|null $hariPct Explicit hari percentage
     * @return array ['landlord_pct' => float, 'hari_pct' => float, 'landlord_party_id' => string, 'hari_party_id' => string, 'rule_snapshot' => array]
     * @throws \Exception
     */
    public function resolveShares(
        string $projectId,
        string $postingDate,
        ?string $sharingRuleId = null,
        ?float $landlordPct = null,
        ?float $hariPct = null
    ): array {
        $project = Project::findOrFail($projectId);
        $postingDateObj = Carbon::parse($postingDate);

        // Get parties
        $landlordParty = $this->partyService->ensureSystemLandlordParty($project->tenant_id);
        $hariPartyId = $project->party_id;

        if (!$hariPartyId) {
            throw new \Exception('Project must have a party_id (Hari party)');
        }

        $ruleSnapshot = [
            'source' => 'inventory_issue',
            'project_id' => $projectId,
            'posting_date' => $postingDateObj->format('Y-m-d'),
        ];

        // Priority 1: Explicit percentages provided
        if ($landlordPct !== null && $hariPct !== null) {
            $ruleSnapshot['resolution_method'] = 'explicit_percentages';
            $ruleSnapshot['landlord_pct'] = $landlordPct;
            $ruleSnapshot['hari_pct'] = $hariPct;

            return [
                'landlord_pct' => $landlordPct,
                'hari_pct' => $hariPct,
                'landlord_party_id' => $landlordParty->id,
                'hari_party_id' => $hariPartyId,
                'rule_snapshot' => $ruleSnapshot,
            ];
        }

        // Priority 2: ShareRule provided
        if ($sharingRuleId) {
            $shareRule = ShareRule::with('lines.party')
                ->where('id', $sharingRuleId)
                ->where('tenant_id', $project->tenant_id)
                ->where('is_active', true)
                ->firstOrFail();

            // Verify rule is effective on posting date
            if ($shareRule->effective_from->gt($postingDateObj)) {
                throw new \Exception("Share rule '{$shareRule->name}' is not effective until {$shareRule->effective_from->format('Y-m-d')}");
            }

            if ($shareRule->effective_to && $shareRule->effective_to->lt($postingDateObj)) {
                throw new \Exception("Share rule '{$shareRule->name}' expired on {$shareRule->effective_to->format('Y-m-d')}");
            }

            // Extract percentages for LANDLORD and HARI roles
            $landlordPct = 0;
            $hariPct = 0;

            foreach ($shareRule->lines as $line) {
                if ($line->role === 'LANDLORD') {
                    $landlordPct = (float) $line->percentage;
                } elseif ($line->role === 'HARI') {
                    $hariPct = (float) $line->percentage;
                }
            }

            // If roles not found, try matching by party_id
            if ($landlordPct == 0 && $hariPct == 0) {
                foreach ($shareRule->lines as $line) {
                    if ($line->party_id === $landlordParty->id) {
                        $landlordPct = (float) $line->percentage;
                    } elseif ($line->party_id === $hariPartyId) {
                        $hariPct = (float) $line->percentage;
                    }
                }
            }

            if ($landlordPct == 0 && $hariPct == 0) {
                throw new \Exception("Share rule '{$shareRule->name}' does not contain LANDLORD or HARI allocations");
            }

            $ruleSnapshot['resolution_method'] = 'share_rule';
            $ruleSnapshot['share_rule_id'] = $shareRule->id;
            $ruleSnapshot['share_rule_name'] = $shareRule->name;
            $ruleSnapshot['share_rule_version'] = $shareRule->version;
            $ruleSnapshot['landlord_pct'] = $landlordPct;
            $ruleSnapshot['hari_pct'] = $hariPct;

            return [
                'landlord_pct' => $landlordPct,
                'hari_pct' => $hariPct,
                'landlord_party_id' => $landlordParty->id,
                'hari_party_id' => $hariPartyId,
                'rule_snapshot' => $ruleSnapshot,
            ];
        }

        // Priority 3: Use ProjectRule defaults
        $projectRule = ProjectRule::where('project_id', $projectId)->first();

        if (!$projectRule) {
            throw new \Exception("Project does not have a ProjectRule configured. Please provide sharing_rule_id or explicit percentages.");
        }

        $landlordPct = (float) $projectRule->profit_split_landlord_pct;
        $hariPct = (float) $projectRule->profit_split_hari_pct;

        $ruleSnapshot['resolution_method'] = 'project_rule_defaults';
        $ruleSnapshot['project_rule_id'] = $projectRule->id;
        $ruleSnapshot['landlord_pct'] = $landlordPct;
        $ruleSnapshot['hari_pct'] = $hariPct;

        return [
            'landlord_pct' => $landlordPct,
            'hari_pct' => $hariPct,
            'landlord_party_id' => $landlordParty->id,
            'hari_party_id' => $hariPartyId,
            'rule_snapshot' => $ruleSnapshot,
        ];
    }
}
