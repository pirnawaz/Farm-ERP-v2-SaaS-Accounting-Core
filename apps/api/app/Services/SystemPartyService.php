<?php

namespace App\Services;

use App\Models\Party;
use Illuminate\Support\Facades\DB;

class SystemPartyService
{
    /**
     * Ensure the system landlord party exists for a tenant.
     * Creates it if missing.
     * 
     * @param string $tenantId
     * @return Party
     */
    public function ensureSystemLandlordParty(string $tenantId): Party
    {
        // Look for existing landlord party
        $landlord = Party::where('tenant_id', $tenantId)
            ->where('name', 'Landlord')
            ->whereJsonContains('party_types', 'LANDLORD')
            ->first();

        if ($landlord) {
            return $landlord;
        }

        // Create if missing
        return Party::create([
            'tenant_id' => $tenantId,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
    }

    /**
     * Check if a party is the system landlord party.
     * 
     * @param Party $party
     * @return bool
     */
    public function isSystemLandlord(Party $party): bool
    {
        return $party->name === 'Landlord' 
            && in_array('LANDLORD', $party->party_types ?? []);
    }
}
