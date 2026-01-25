<?php

namespace App\Accounting\Rules;

interface RuleResolver
{
    /**
     * Resolve rules for a DailyBookEntry at posting time.
     * 
     * @param string $tenantId
     * @param string $dailyBookEntryId
     * @param string $postingDate YYYY-MM-DD format
     * @return RuleResolutionResult
     */
    public function resolveDailyBookEntry(
        string $tenantId,
        string $dailyBookEntryId,
        string $postingDate
    ): RuleResolutionResult;
}
