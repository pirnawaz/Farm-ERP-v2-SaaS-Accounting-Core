<?php

namespace Tests\Traits;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a tenant and one user per tenant role (tenant_admin, accountant, operator)
 * for role-based API tests. Use with dev identity headers (X-Tenant-Id, X-User-Id, X-User-Role).
 */
trait CreatesTenantWithRoleUsers
{
    /** @var array{tenant: Tenant, user_ids: array<string, string>} */
    private ?array $tenantWithRoleUsers = null;

    /**
     * Ensure tenant and role users exist; return tenant and map of role => user_id.
     * Idempotent within the same test (cached after first call).
     *
     * @return array{tenant: Tenant, user_ids: array<string, string>}
     */
    protected function tenantWithRoleUsers(): array
    {
        if ($this->tenantWithRoleUsers !== null) {
            return $this->tenantWithRoleUsers;
        }

        $tenant = Tenant::create([
            'name' => 'Test Tenant ' . Str::random(4),
            'status' => 'active',
        ]);

        $roles = ['tenant_admin', 'accountant', 'operator'];
        $userIds = [];
        foreach ($roles as $role) {
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Test ' . str_replace('_', ' ', ucfirst($role)),
                'email' => strtolower($role) . '-' . Str::random(6) . '@test.local',
                'password' => null,
                'role' => $role,
                'is_enabled' => true,
            ]);
            $userIds[$role] = $user->id;
        }

        $this->tenantWithRoleUsers = ['tenant' => $tenant, 'user_ids' => $userIds];
        return $this->tenantWithRoleUsers;
    }

    /**
     * Headers for tenant-scoped request with dev identity (testing env).
     */
    protected function tenantRoleHeaders(string $role): array
    {
        ['tenant' => $tenant, 'user_ids' => $userIds] = $this->tenantWithRoleUsers();
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Id' => $userIds[$role] ?? $userIds['tenant_admin'],
            'X-User-Role' => $role,
        ];
    }
}
