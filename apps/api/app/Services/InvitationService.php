<?php

namespace App\Services;

use App\Models\UserInvitation;

class InvitationService
{
    /**
     * Create or reuse an invitation for a tenant. Email is normalized (trim + lower).
     * Returns invite_link, expires_in_hours, email, role, is_new, invitation_id.
     */
    public function createInvitationForTenant(
        string $tenantId,
        string $email,
        string $role,
        string $invitedByUserId,
        int $ttlHours = 168
    ): array {
        $email = $this->normalizeEmail($email);
        [$token, $isNew] = UserInvitation::createOrReuseInvitation(
            $tenantId,
            $email,
            $role,
            $invitedByUserId,
            $ttlHours
        );

        $invitation = UserInvitation::where('tenant_id', $tenantId)->where('email', $email)->first();
        $baseUrl = rtrim(config('app.front_url', config('app.url')), '/');
        $inviteLink = "{$baseUrl}/accept-invite?token=" . urlencode($token);

        return [
            'invite_link' => $inviteLink,
            'expires_in_hours' => $ttlHours,
            'email' => $email,
            'role' => $role,
            'is_new' => $isNew,
            'invitation_id' => $invitation?->id,
        ];
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
