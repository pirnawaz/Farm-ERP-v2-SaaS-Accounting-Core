<?php

namespace App\Services;

use App\Models\ShareRule;
use App\Models\ShareRuleLine;
use App\Models\Settlement;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShareRuleService
{
    /**
     * Create a new share rule with validation.
     * 
     * @param array $data
     * @return ShareRule
     * @throws \Exception
     */
    public function create(array $data): ShareRule
    {
        return DB::transaction(function () use ($data) {
            // Validate percentages sum to 100
            if (isset($data['lines']) && is_array($data['lines'])) {
                $this->validatePercentages($data['lines']);
            }

            // Validate no overlapping periods for same scope
            if (isset($data['applies_to']) && isset($data['effective_from'])) {
                $this->validateOverlappingPeriods(
                    $data['applies_to'],
                    $data['effective_from'],
                    $data['effective_to'] ?? null,
                    null
                );
            }

            // Get next version if not provided
            $version = $data['version'] ?? 1;
            if (!isset($data['version'])) {
                $maxVersion = ShareRule::where('tenant_id', $data['tenant_id'])
                    ->where('applies_to', $data['applies_to'])
                    ->max('version');
                $version = ($maxVersion ?? 0) + 1;
            }

            // Create share rule
            $shareRule = ShareRule::create([
                'tenant_id' => $data['tenant_id'],
                'name' => $data['name'],
                'applies_to' => $data['applies_to'],
                'basis' => $data['basis'] ?? 'MARGIN',
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'version' => $version,
            ]);

            // Create share rule lines
            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $lineData) {
                    ShareRuleLine::create([
                        'share_rule_id' => $shareRule->id,
                        'party_id' => $lineData['party_id'],
                        'percentage' => $lineData['percentage'],
                        'role' => $lineData['role'] ?? null,
                    ]);
                }
            }

            return $shareRule->load('lines.party');
        });
    }

    /**
     * Update a share rule (only if not used in posted settlements).
     * 
     * @param string $id
     * @param array $data
     * @return ShareRule
     * @throws \Exception
     */
    public function update(string $id, array $data): ShareRule
    {
        return DB::transaction(function () use ($id, $data) {
            $shareRule = ShareRule::findOrFail($id);

            // Check if rule is used in posted settlements
            $usedInSettlements = Settlement::where('share_rule_id', $id)
                ->where('status', 'POSTED')
                ->exists();

            if ($usedInSettlements) {
                throw new \Exception('Cannot update share rule that is used in posted settlements');
            }

            // Validate percentages if lines are provided
            if (isset($data['lines']) && is_array($data['lines'])) {
                $this->validatePercentages($data['lines']);
            }

            // Validate overlapping periods if dates are being changed
            if (isset($data['applies_to']) || isset($data['effective_from'])) {
                $appliesTo = $data['applies_to'] ?? $shareRule->applies_to;
                $effectiveFrom = $data['effective_from'] ?? $shareRule->effective_from;
                $effectiveTo = $data['effective_to'] ?? $shareRule->effective_to;

                $this->validateOverlappingPeriods(
                    $appliesTo,
                    $effectiveFrom,
                    $effectiveTo,
                    $id
                );
            }

            // Update share rule
            $shareRule->update(array_filter([
                'name' => $data['name'] ?? null,
                'applies_to' => $data['applies_to'] ?? null,
                'basis' => $data['basis'] ?? null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => $data['is_active'] ?? null,
            ], fn($value) => $value !== null));

            // Update lines if provided
            if (isset($data['lines']) && is_array($data['lines'])) {
                // Delete existing lines
                $shareRule->lines()->delete();

                // Create new lines
                foreach ($data['lines'] as $lineData) {
                    ShareRuleLine::create([
                        'share_rule_id' => $shareRule->id,
                        'party_id' => $lineData['party_id'],
                        'percentage' => $lineData['percentage'],
                        'role' => $lineData['role'] ?? null,
                    ]);
                }
            }

            return $shareRule->fresh('lines.party');
        });
    }

    /**
     * List share rules with filters.
     * 
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function list(array $filters = [])
    {
        $query = ShareRule::with('lines.party');

        if (isset($filters['tenant_id'])) {
            $query->where('tenant_id', $filters['tenant_id']);
        }

        if (isset($filters['applies_to'])) {
            $query->where('applies_to', $filters['applies_to']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['crop_cycle_id'])) {
            // For crop cycle rules, check if effective date overlaps with crop cycle
            $cropCycle = \App\Models\CropCycle::find($filters['crop_cycle_id']);
            if ($cropCycle) {
                $query->where(function ($q) use ($cropCycle) {
                    $q->where('applies_to', 'CROP_CYCLE')
                        ->where(function ($q2) use ($cropCycle) {
                            $q2->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', $cropCycle->start_date);
                        })
                        ->where('effective_from', '<=', $cropCycle->end_date ?? Carbon::now());
                });
            }
        }

        return $query->orderBy('version', 'desc')->get();
    }

    /**
     * Resolve the active share rule for a given context.
     * 
     * @param string $tenantId
     * @param string $saleDate YYYY-MM-DD format
     * @param string|null $cropCycleId
     * @param string|null $projectId
     * @return ShareRule|null
     */
    public function resolveRule(string $tenantId, string $saleDate, ?string $cropCycleId = null, ?string $projectId = null): ?ShareRule
    {
        $saleDateObj = Carbon::parse($saleDate);

        // Try to find rule in order of specificity: SALE > PROJECT > CROP_CYCLE
        $appliesToOrder = ['SALE', 'PROJECT', 'CROP_CYCLE'];
        
        foreach ($appliesToOrder as $appliesTo) {
            $query = ShareRule::where('tenant_id', $tenantId)
                ->where('applies_to', $appliesTo)
                ->where('is_active', true)
                ->where('effective_from', '<=', $saleDateObj->format('Y-m-d'))
                ->where(function ($q) use ($saleDateObj) {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $saleDateObj->format('Y-m-d'));
                });

            // Add scope-specific filters
            if ($appliesTo === 'PROJECT' && $projectId) {
                // For project rules, we'd need a project_id column in share_rules
                // For now, we'll skip project-specific rules
                continue;
            } elseif ($appliesTo === 'CROP_CYCLE' && $cropCycleId) {
                // For crop cycle rules, verify the sale date is within the crop cycle
                $cropCycle = \App\Models\CropCycle::find($cropCycleId);
                if ($cropCycle) {
                    $query->where(function ($q) use ($cropCycle) {
                        // Rule effective period should overlap with crop cycle
                        $q->where(function ($q2) use ($cropCycle) {
                            $q2->whereNull('effective_to')
                                ->orWhere('effective_to', '>=', $cropCycle->start_date);
                        })
                        ->where('effective_from', '<=', $cropCycle->end_date ?? Carbon::now());
                    });
                }
            }

            $rule = $query->orderBy('version', 'desc')->first();
            
            if ($rule) {
                return $rule->load('lines.party');
            }
        }

        return null;
    }

    /**
     * Validate that percentages sum to 100.
     * 
     * @param array $lines
     * @throws \Exception
     */
    public function validatePercentages(array $lines): void
    {
        $total = 0;
        foreach ($lines as $line) {
            if (!isset($line['percentage'])) {
                throw new \Exception('Percentage is required for each share rule line');
            }
            $total += (float) $line['percentage'];
        }

        // Allow small floating point differences (0.01)
        if (abs($total - 100.0) > 0.01) {
            throw new \Exception("Share rule percentages must sum to 100. Current sum: {$total}");
        }
    }

    /**
     * Validate no overlapping effective periods for same scope.
     * 
     * @param string $appliesTo
     * @param string $effectiveFrom
     * @param string|null $effectiveTo
     * @param string|null $excludeId
     * @throws \Exception
     */
    public function validateOverlappingPeriods(string $appliesTo, string $effectiveFrom, ?string $effectiveTo = null, ?string $excludeId = null): void
    {
        $effectiveFromObj = Carbon::parse($effectiveFrom);
        $effectiveToObj = $effectiveTo ? Carbon::parse($effectiveTo) : null;

        $query = ShareRule::where('applies_to', $appliesTo)
            ->where('is_active', true);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $overlappingRules = $query->get()->filter(function ($rule) use ($effectiveFromObj, $effectiveToObj) {
            $ruleFrom = Carbon::parse($rule->effective_from);
            $ruleTo = $rule->effective_to ? Carbon::parse($rule->effective_to) : null;

            // Check if periods overlap
            // Periods overlap if: ruleFrom <= effectiveTo AND (ruleTo === null OR ruleTo >= effectiveFrom)
            if ($effectiveToObj) {
                if ($ruleFrom->lte($effectiveToObj) && (!$ruleTo || $ruleTo->gte($effectiveFromObj))) {
                    return true;
                }
            } else {
                // If new rule has no end date, it overlaps if rule starts before or at the same time
                // and either rule has no end or rule ends after new rule starts
                if ($ruleFrom->lte($effectiveFromObj) && (!$ruleTo || $ruleTo->gte($effectiveFromObj))) {
                    return true;
                }
                // Or if rule starts after new rule starts but before new rule would theoretically end
                if ($ruleFrom->gte($effectiveFromObj) && (!$ruleTo || $ruleTo->gte($effectiveFromObj))) {
                    return true;
                }
            }

            return false;
        });

        if ($overlappingRules->isNotEmpty()) {
            $ruleNames = $overlappingRules->pluck('name')->join(', ');
            throw new \Exception("Share rule effective period overlaps with existing active rules: {$ruleNames}");
        }
    }
}
