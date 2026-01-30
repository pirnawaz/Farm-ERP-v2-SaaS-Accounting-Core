<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Party;

class PartyAccountService
{
    public function __construct(
        private SystemAccountService $accountService
    ) {}

    /**
     * Get the party control account for a party (single account, sign-driven balance).
     * Debit = we owe them, Credit = they owe us.
     *
     * @param string $tenantId
     * @param string $partyId
     * @return Account
     * @throws \Exception if party not found or no mapping for party types
     */
    public function getPartyControlAccount(string $tenantId, string $partyId): Account
    {
        $party = Party::where('id', $partyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $types = $party->party_types ?? [];
        if (in_array('HARI', $types)) {
            return $this->accountService->getByCode($tenantId, 'PARTY_CONTROL_HARI');
        }
        if (in_array('LANDLORD', $types)) {
            return $this->accountService->getByCode($tenantId, 'PARTY_CONTROL_LANDLORD');
        }
        if (in_array('KAMDAR', $types)) {
            return $this->accountService->getByCode($tenantId, 'PARTY_CONTROL_KAMDAR');
        }

        // Fallback: try PARTY_CONTROL_HARI for backward compat if party is project's hari
        throw new \Exception("No party control account mapping for party types: " . implode(', ', $types));
    }

    /**
     * Get party control account by role/code (for use when party_id is not available).
     *
     * @param string $tenantId
     * @param string $role One of: HARI, LANDLORD, KAMDAR
     * @return Account
     */
    public function getPartyControlAccountByRole(string $tenantId, string $role): Account
    {
        $code = match (strtoupper($role)) {
            'HARI' => 'PARTY_CONTROL_HARI',
            'LANDLORD' => 'PARTY_CONTROL_LANDLORD',
            'KAMDAR' => 'PARTY_CONTROL_KAMDAR',
            default => throw new \Exception("Unknown party role for control account: {$role}"),
        };
        return $this->accountService->getByCode($tenantId, $code);
    }

    /**
     * Get the advance account for a party type (distinct from control account).
     *
     * @param string $tenantId
     * @param string $advanceType One of: HARI_ADVANCE, VENDOR_ADVANCE, LOAN
     * @return Account
     */
    public function getAdvanceAccount(string $tenantId, string $advanceType): Account
    {
        $code = match (strtoupper($advanceType)) {
            'HARI_ADVANCE', 'HARI' => 'ADVANCE_HARI',
            'VENDOR_ADVANCE', 'VENDOR' => 'ADVANCE_VENDOR',
            'LOAN' => 'LOAN_RECEIVABLE',
            default => throw new \Exception("Unknown advance type: {$advanceType}"),
        };
        return $this->accountService->getByCode($tenantId, $code);
    }
}
