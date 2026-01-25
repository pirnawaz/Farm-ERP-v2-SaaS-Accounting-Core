<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

class SystemAccountService
{
    /**
     * Get a system account by code for a tenant.
     * 
     * @param string $tenantId
     * @param string $code Account code (e.g., 'CASH', 'PROJECT_REVENUE')
     * @return Account
     * @throws \Exception if account not found
     */
    public function getByCode(string $tenantId, string $code): Account
    {
        $account = Account::where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if (!$account) {
            throw new \Exception("System account with code '{$code}' not found for tenant");
        }

        return $account;
    }

    /**
     * Get multiple system accounts by codes.
     * 
     * @param string $tenantId
     * @param array $codes Array of account codes
     * @return \Illuminate\Support\Collection
     */
    public function getByCodes(string $tenantId, array $codes)
    {
        return Account::where('tenant_id', $tenantId)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');
    }
}
