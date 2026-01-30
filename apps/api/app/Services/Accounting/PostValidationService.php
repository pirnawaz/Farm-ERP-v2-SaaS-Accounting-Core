<?php

namespace App\Services\Accounting;

use App\Models\Account;
use InvalidArgumentException;

/**
 * Validates ledger lines before persisting to prevent posting to deprecated accounts.
 * Call just before persisting ledger entries in posting services.
 */
class PostValidationService
{
    /**
     * Validate that none of the ledger lines post to a deprecated account.
     *
     * @param string $tenantId
     * @param array<int, array{account_id: string, ...}> $ledgerLines Each line must have 'account_id'
     * @return void
     * @throws InvalidArgumentException If any line uses a deprecated account (message includes account code)
     */
    public function validateNoDeprecatedAccounts(string $tenantId, array $ledgerLines): void
    {
        if (empty($ledgerLines)) {
            return;
        }

        $accountIds = array_unique(array_filter(array_column($ledgerLines, 'account_id')));
        if (empty($accountIds)) {
            return;
        }

        $deprecated = config('accounting.deprecated_codes', []);
        if (empty($deprecated)) {
            return;
        }

        $codesById = Account::where('tenant_id', $tenantId)
            ->whereIn('id', $accountIds)
            ->pluck('code', 'id')
            ->all();

        foreach ($accountIds as $id) {
            $code = $codesById[$id] ?? null;
            if ($code !== null && in_array($code, $deprecated, true)) {
                throw new InvalidArgumentException(
                    "Posting to deprecated account code is not allowed: {$code}. Use PARTY_CONTROL_* for party balances and PROFIT_DISTRIBUTION_CLEARING for settlement."
                );
            }
        }
    }
}
