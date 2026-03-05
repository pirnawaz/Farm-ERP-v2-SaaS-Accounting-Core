<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class TenantUserManagementTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    /** @test */
    public function disable_user_syncs_membership_and_user_cannot_access_tenant_routes(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $identity = Identity::create([
            'email' => 'admin@t1.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $admin->update(['identity_id' => $identity->id]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => Hash::make('old'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        $opIdentity = Identity::create([
            'email' => 'op@t1.test',
            'password_hash' => Hash::make('old'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $operator->update(['identity_id' => $opIdentity->id]);
        TenantMembership::create([
            'identity_id' => $opIdentity->id,
            'tenant_id' => $tenant->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'admin@t1.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);
        $select = $this->withAuthCookieFrom($login)->postJson('/api/auth/select-tenant', ['tenant_id' => $tenant->id]);
        $select->assertStatus(200);

        $r = $this->withAuthCookieFrom($select)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->putJson('/api/tenant/users/' . $operator->id, ['is_enabled' => false]);
        $r->assertStatus(200);
        $operator->refresh();
        $this->assertFalse($operator->is_enabled);

        $membership = TenantMembership::where('identity_id', $opIdentity->id)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($membership);
        $this->assertFalse($membership->is_enabled);

        $opLogin = $this->postJson('/api/auth/login', ['email' => 'op@t1.test', 'password' => 'old']);
        $opLogin->assertStatus(403);
    }

    /** @test */
    public function enable_user_after_disable_restores_access(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $identity = Identity::create([
            'email' => 'admin@t1.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $admin->update(['identity_id' => $identity->id]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => false,
        ]);
        $opIdentity = Identity::create([
            'email' => 'op@t1.test',
            'password_hash' => Hash::make('pwd'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $operator->update(['identity_id' => $opIdentity->id]);
        TenantMembership::create([
            'identity_id' => $opIdentity->id,
            'tenant_id' => $tenant->id,
            'role' => 'operator',
            'is_enabled' => false,
        ]);

        $login = $this->postJson('/api/auth/login', ['email' => 'admin@t1.test', 'password' => 'secret']);
        $select = $this->withAuthCookieFrom($login)->postJson('/api/auth/select-tenant', ['tenant_id' => $tenant->id]);
        $select->assertStatus(200);

        $r = $this->withAuthCookieFrom($select)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->putJson('/api/tenant/users/' . $operator->id, ['is_enabled' => true]);
        $r->assertStatus(200);
        $operator->refresh();
        $this->assertTrue($operator->is_enabled);
        $membership = TenantMembership::where('identity_id', $opIdentity->id)->where('tenant_id', $tenant->id)->first();
        $this->assertTrue($membership->is_enabled);
    }

    /** @test */
    public function reset_password_updates_identity_and_unified_login_succeeds(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $identity = Identity::create([
            'email' => 'admin@t1.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $admin->update(['identity_id' => $identity->id]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => Hash::make('old'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        $opIdentity = Identity::create([
            'email' => 'op@t1.test',
            'password_hash' => Hash::make('old'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $operator->update(['identity_id' => $opIdentity->id]);
        TenantMembership::create([
            'identity_id' => $opIdentity->id,
            'tenant_id' => $tenant->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/auth/login', ['email' => 'admin@t1.test', 'password' => 'secret']);
        $select = $this->withAuthCookieFrom($login)->postJson('/api/auth/select-tenant', ['tenant_id' => $tenant->id]);
        $select->assertStatus(200);

        $r = $this->withAuthCookieFrom($select)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/tenant/users/' . $operator->id . '/reset-password', ['new_password' => 'newpass123']);
        $r->assertStatus(200);

        $opIdentity->refresh();
        $this->assertTrue(Hash::check('newpass123', $opIdentity->password_hash));

        $opLogin = $this->postJson('/api/auth/login', ['email' => 'op@t1.test', 'password' => 'newpass123']);
        $opLogin->assertStatus(200);
        $this->assertNotEmpty($opLogin->json('token'), 'Login with new password must succeed and return token');
    }

    /** @test */
    public function remove_from_tenant_disables_membership_and_identity_remains(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $identity = Identity::create([
            'email' => 'admin@t1.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $admin->update(['identity_id' => $identity->id]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $operator = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Op',
            'email' => 'op@t1.test',
            'password' => Hash::make('pwd'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        $opIdentity = Identity::create([
            'email' => 'op@t1.test',
            'password_hash' => Hash::make('pwd'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $operator->update(['identity_id' => $opIdentity->id]);
        TenantMembership::create([
            'identity_id' => $opIdentity->id,
            'tenant_id' => $tenant->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/auth/login', ['email' => 'admin@t1.test', 'password' => 'secret']);
        $select = $this->withAuthCookieFrom($login)->postJson('/api/auth/select-tenant', ['tenant_id' => $tenant->id]);
        $select->assertStatus(200);

        $r = $this->withAuthCookieFrom($select)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson('/api/tenant/users/' . $operator->id);
        $r->assertStatus(204);

        $operator->refresh();
        $this->assertFalse($operator->is_enabled);
        $membership = TenantMembership::where('identity_id', $opIdentity->id)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($membership);
        $this->assertFalse($membership->is_enabled);
        $this->assertDatabaseHas('identities', ['id' => $opIdentity->id]);
    }
}
