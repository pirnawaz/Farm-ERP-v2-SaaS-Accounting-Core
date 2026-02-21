<?php

namespace App\Services;

class PlanModules
{
    /**
     * Allowed module keys for the given plan_key.
     * Null/empty plan_key: if default_allow_all then all keys from config modules; else [].
     *
     * @return array<string>
     */
    public function getAllowedModuleKeysForPlan(?string $planKey): array
    {
        $plans = config('plans.plans', []);
        $defaultAllowAll = config('plans.default_allow_all', true);

        if ($planKey === null || $planKey === '') {
            if ($defaultAllowAll) {
                $allKeys = array_merge(
                    array_keys(config('modules.tiers', [])),
                    array_keys(config('modules.dependencies', []))
                );
                return array_values(array_unique($allKeys));
            }
            return [];
        }

        return $plans[$planKey] ?? [];
    }

    /**
     * Whether the given module key is allowed for the plan.
     */
    public function isModuleAllowedForPlan(string $moduleKey, ?string $planKey): bool
    {
        $allowed = $this->getAllowedModuleKeysForPlan($planKey);
        return in_array($moduleKey, $allowed, true);
    }
}
