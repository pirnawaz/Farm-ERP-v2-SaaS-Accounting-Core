<?php

namespace App\Policies;

class PostingPolicy
{
    /**
     * Determine if the user can post accounting documents.
     * Only tenant_admin and accountant can post.
     */
    public function post($user): bool
    {
        return in_array($user->role, ['tenant_admin', 'accountant']);
    }
}
