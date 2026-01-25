<?php

namespace App\Policies;

use App\Models\Party;
use App\Services\SystemPartyService;

class PartyPolicy
{
    public function __construct(
        private SystemPartyService $partyService
    ) {}

    /**
     * Determine if the user can view any models.
     */
    public function viewAny($user): bool
    {
        return in_array($user->role, ['tenant_admin', 'accountant']);
    }

    /**
     * Determine if the user can view the model.
     */
    public function view($user, Party $party): bool
    {
        return in_array($user->role, ['tenant_admin', 'accountant']) 
            && $user->tenant_id === $party->tenant_id;
    }

    /**
     * Determine if the user can create models.
     */
    public function create($user): bool
    {
        return in_array($user->role, ['tenant_admin', 'accountant']);
    }

    /**
     * Determine if the user can update the model.
     */
    public function update($user, Party $party): bool
    {
        if (!in_array($user->role, ['tenant_admin', 'accountant'])) {
            return false;
        }

        if ($user->tenant_id !== $party->tenant_id) {
            return false;
        }

        // Prevent deleting system landlord party
        if ($this->partyService->isSystemLandlord($party)) {
            return false; // Can't modify system party
        }

        return true;
    }

    /**
     * Determine if the user can delete the model.
     */
    public function delete($user, Party $party): bool
    {
        if (!in_array($user->role, ['tenant_admin', 'accountant'])) {
            return false;
        }

        if ($user->tenant_id !== $party->tenant_id) {
            return false;
        }

        // Prevent deleting system landlord party
        if ($this->partyService->isSystemLandlord($party)) {
            return false;
        }

        return true;
    }
}
