<?php

namespace App\Policies;

class ReversalPolicy
{
    /**
     * Determine if the user can reverse accounting documents.
     * Only tenant_admin and accountant can reverse.
     */
    public function reverse($user): bool
    {
        return in_array($user->role, ['tenant_admin', 'accountant']);
    }
}
