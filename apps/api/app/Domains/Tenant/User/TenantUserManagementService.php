<?php

namespace App\Domains\Tenant\User;

use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant-scoped user management: Identity + TenantMembership as source of truth.
 * Keeps users.is_enabled in sync; does not hard-delete Identity.
 */
class TenantUserManagementService
{
    /**
     * Resolve or create Identity for the user and ensure TenantMembership exists.
     * Links user.identity_id if missing. Returns the Identity or null if user has no email.
     */
    public function resolveOrCreateIdentity(User $user, Tenant $tenant, string $role): ?Identity
    {
        $email = $user->email ? strtolower(trim((string) $user->email)) : '';
        if ($email === '') {
            return null;
        }

        $identity = null;
        if ($user->identity_id) {
            $identity = Identity::find($user->identity_id);
        }
        if (!$identity) {
            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
        }
        if (!$identity) {
            $identity = Identity::create([
                'email' => $email,
                'password_hash' => $user->password ?: Hash::make(Str::random(32)),
                'is_enabled' => (bool) $user->is_enabled,
                'is_platform_admin' => false,
                'token_version' => 1,
            ]);
        }

        if ($user->identity_id !== $identity->id) {
            $user->update(['identity_id' => $identity->id]);
        }

        $membership = TenantMembership::where('identity_id', $identity->id)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (!$membership) {
            TenantMembership::create([
                'identity_id' => $identity->id,
                'tenant_id' => $tenant->id,
                'role' => $role,
                'is_enabled' => (bool) $user->is_enabled,
            ]);
        } else {
            $membership->update([
                'role' => $role,
                'is_enabled' => $membership->is_enabled, // preserve; use setEnabled to change
            ]);
        }

        return $identity;
    }

    /**
     * Set password for unified login and legacy: updates Identity.password_hash and User.password.
     * Increments Identity.token_version to invalidate old sessions.
     */
    public function setPassword(User $user, string $newPassword): void
    {
        $passwordHash = Hash::make($newPassword);
        $user->update([
            'password' => $passwordHash,
            'last_password_change_at' => now(),
            'token_version' => ($user->token_version ?? 0) + 1,
        ]);

        $identity = $user->identity_id ? Identity::find($user->identity_id) : null;
        if (!$identity && $user->email) {
            $email = strtolower(trim((string) $user->email));
            $identity = Identity::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($identity) {
                $user->update(['identity_id' => $identity->id]);
            } else {
                $tenant = $user->tenant_id ? \App\Models\Tenant::find($user->tenant_id) : null;
                if ($tenant) {
                    $identity = $this->resolveOrCreateIdentity($user, $tenant, $user->role);
                }
            }
        }
        if ($identity) {
            $identity->update([
                'password_hash' => $passwordHash,
                'token_version' => $identity->token_version + 1,
            ]);
        }
    }

    /**
     * Enable or disable user in this tenant: updates TenantMembership.is_enabled and User.is_enabled.
     * Membership is the authoritative gate for ResolveTenantAuth.
     */
    public function setEnabled(User $user, Tenant $tenant, bool $enabled): void
    {
        $user->update(['is_enabled' => $enabled]);

        $identityId = $user->identity_id;
        if (!$identityId) {
            $identity = $this->resolveOrCreateIdentity($user, $tenant, $user->role);
            $identityId = $identity?->id;
        }
        if ($identityId) {
            TenantMembership::where('identity_id', $identityId)
                ->where('tenant_id', $tenant->id)
                ->update(['is_enabled' => $enabled]);
        }
    }

    /**
     * Remove user from farm: disable TenantMembership (and User.is_enabled) so they lose access.
     * Does not hard-delete Identity or User.
     */
    public function removeFromTenant(User $user, Tenant $tenant): void
    {
        $user->update(['is_enabled' => false]);

        $identityId = $user->identity_id;
        if (!$identityId) {
            $identity = $this->resolveOrCreateIdentity($user, $tenant, $user->role);
            $identityId = $identity?->id;
        }
        if ($identityId) {
            TenantMembership::where('identity_id', $identityId)
                ->where('tenant_id', $tenant->id)
                ->update(['is_enabled' => false]);
        }
    }

    /**
     * Update role for user in this tenant: User.role and TenantMembership.role.
     */
    public function updateRole(User $user, Tenant $tenant, string $role): void
    {
        $user->update(['role' => $role]);

        $identityId = $user->identity_id;
        if (!$identityId) {
            $identity = $this->resolveOrCreateIdentity($user, $tenant, $role);
            $identityId = $identity?->id;
        }
        if ($identityId) {
            TenantMembership::where('identity_id', $identityId)
                ->where('tenant_id', $tenant->id)
                ->update(['role' => $role]);
        }
    }
}

