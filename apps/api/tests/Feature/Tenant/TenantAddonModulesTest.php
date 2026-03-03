<?php

namespace Tests\Feature\Tenant;

use App\Models\Tenant;
use App\Models\TenantAddonModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAddonModulesTest extends TestCase
{
    use RefreshDatabase;

    private function tenantHeaders(string $tenantId, string $userId, string $role = 'tenant_admin'): array
    {
        return [
            'X-Tenant-Id' => $tenantId,
            'X-User-Id' => $userId,
            'X-User-Role' => $role,
        ];
    }

    /** @test */
    public function when_no_rows_exist_returns_both_keys_false(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->getJson('/api/tenant/addon-modules');

        $r->assertStatus(200);
        $r->assertJsonPath('modules.orchards', false);
        $r->assertJsonPath('modules.livestock', false);
    }

    /** @test */
    public function when_orchards_enabled_returns_orchards_true_livestock_false(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        TenantAddonModule::create([
            'tenant_id' => $tenant->id,
            'module_key' => 'orchards',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->getJson('/api/tenant/addon-modules');

        $r->assertStatus(200);
        $r->assertJsonPath('modules.orchards', true);
        $r->assertJsonPath('modules.livestock', false);
    }

    /** @test */
    public function tenant_isolation_modules_from_tenant_a_not_visible_to_tenant_b(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'status' => 'active']);
        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'User A',
            'email' => 'a@a.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'name' => 'User B',
            'email' => 'b@b.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        TenantAddonModule::create([
            'tenant_id' => $tenantA->id,
            'module_key' => 'orchards',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);
        TenantAddonModule::create([
            'tenant_id' => $tenantA->id,
            'module_key' => 'livestock',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $rB = $this->withHeaders($this->tenantHeaders($tenantB->id, $userB->id))
            ->getJson('/api/tenant/addon-modules');

        $rB->assertStatus(200);
        $rB->assertJsonPath('modules.orchards', false);
        $rB->assertJsonPath('modules.livestock', false);

        $rA = $this->withHeaders($this->tenantHeaders($tenantA->id, $userA->id))
            ->getJson('/api/tenant/addon-modules');

        $rA->assertStatus(200);
        $rA->assertJsonPath('modules.orchards', true);
        $rA->assertJsonPath('modules.livestock', true);
    }

    /** @test */
    public function disabled_row_returns_false(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        TenantAddonModule::create([
            'tenant_id' => $tenant->id,
            'module_key' => 'orchards',
            'is_enabled' => false,
            'enabled_at' => null,
            'disabled_at' => now(),
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->getJson('/api/tenant/addon-modules');

        $r->assertStatus(200);
        $r->assertJsonPath('modules.orchards', false);
        $r->assertJsonPath('modules.livestock', false);
    }

    /** @test */
    public function tenant_admin_can_enable_orchards(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->patchJson('/api/tenant/addon-modules/orchards', ['is_enabled' => true]);

        $r->assertStatus(200);
        $r->assertJsonPath('modules.orchards', true);
        $r->assertJsonPath('modules.livestock', false);

        $this->assertDatabaseHas('tenant_addon_modules', [
            'tenant_id' => $tenant->id,
            'module_key' => 'orchards',
            'is_enabled' => true,
        ]);
    }

    /** @test */
    public function tenant_admin_can_disable_orchards(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        TenantAddonModule::create([
            'tenant_id' => $tenant->id,
            'module_key' => 'orchards',
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->patchJson('/api/tenant/addon-modules/orchards', ['is_enabled' => false]);

        $r->assertStatus(200);
        $r->assertJsonPath('modules.orchards', false);

        $this->assertDatabaseHas('tenant_addon_modules', [
            'tenant_id' => $tenant->id,
            'module_key' => 'orchards',
            'is_enabled' => false,
        ]);
    }

    /** @test */
    public function accountant_cannot_patch_addon_modules(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Acc',
            'email' => 'acc@t1.test',
            'password' => null,
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id, 'accountant'))
            ->patchJson('/api/tenant/addon-modules/orchards', ['is_enabled' => true]);

        $r->assertStatus(403);
    }

    /** @test */
    public function operator_cannot_patch_addon_modules(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id, 'operator'))
            ->patchJson('/api/tenant/addon-modules/orchards', ['is_enabled' => true]);

        $r->assertStatus(403);
    }

    /** @test */
    public function unknown_module_key_returns_404(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders($tenant->id, $user->id))
            ->patchJson('/api/tenant/addon-modules/unknown', ['is_enabled' => true]);

        $r->assertStatus(404);
    }
}
