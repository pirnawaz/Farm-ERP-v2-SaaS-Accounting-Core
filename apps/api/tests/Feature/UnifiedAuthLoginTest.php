<?php

namespace Tests\Feature;

use App\Helpers\AuthToken;
use App\Models\Identity;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class UnifiedAuthLoginTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    /** @test */
    public function unified_login_platform_admin_returns_mode_platform(): void
    {
        $identity = Identity::create([
            'email' => 'platform@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => true,
            'token_version' => 1,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'platform@test.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'platform');
        $response->assertJsonPath('tenant', null);
        $response->assertJsonPath('identity.email', 'platform@test.test');
        $response->assertCookie('farm_erp_auth_token');
    }

    /** @test */
    public function unified_login_single_tenant_returns_mode_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $identity = Identity::create([
            'email' => 'user@acme.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $user = User::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'user@acme.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@acme.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'tenant');
        $response->assertJsonPath('tenant.id', $tenant->id);
        $response->assertJsonPath('tenant.slug', 'acme');
        $response->assertJsonPath('user.role', 'tenant_admin');
    }

    /** @test */
    public function unified_login_multi_tenant_returns_mode_select_tenant(): void
    {
        $t1 = Tenant::create(['name' => 'Farm One', 'slug' => 'farm-one', 'status' => 'active']);
        $t2 = Tenant::create(['name' => 'Farm Two', 'slug' => 'farm-two', 'status' => 'active']);
        $identity = Identity::create([
            'email' => 'multi@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $t1->id,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $t2->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'multi@test.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('mode', 'select_tenant');
        $response->assertJsonCount(2, 'tenants');
    }

    /** @test */
    public function select_tenant_sets_active_tenant_and_returns_tenant_mode(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $identity = Identity::create([
            'email' => 'u@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'role' => 'accountant',
            'is_enabled' => true,
        ]);
        $user = User::create([
            'identity_id' => $identity->id,
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@test.test',
            'password' => Hash::make('secret'),
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'u@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('mode', 'tenant');

        $tenantRoute = $this->withAuthCookieFrom($login)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/auth/me');
        $tenantRoute->assertStatus(200);
        $tenantRoute->assertJsonPath('user.role', 'accountant');
        $tenantRoute->assertJsonPath('tenant.id', $tenant->id);
    }

    /** @test */
    public function tenant_route_without_active_tenant_fails_with_clear_error(): void
    {
        $t1 = Tenant::create(['name' => 'Farm One', 'slug' => 'farm-one', 'status' => 'active']);
        $t2 = Tenant::create(['name' => 'Farm Two', 'slug' => 'farm-two', 'status' => 'active']);
        $identity = Identity::create([
            'email' => 'u@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $t1->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);
        TenantMembership::create([
            'identity_id' => $identity->id,
            'tenant_id' => $t2->id,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'u@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('mode', 'select_tenant');
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $meWithoutTenant = $this->withAuthCookieFrom($login)->getJson('/api/auth/me');
        $meWithoutTenant->assertStatus(422);
    }

    /** @test */
    public function select_tenant_requires_authenticated_session(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        $response = $this->postJson('/api/auth/select-tenant', [
            'tenant_id' => $tenant->id,
        ]);
        $response->assertStatus(401);
    }

    /** @test */
    public function platform_route_with_identity_token_succeeds(): void
    {
        $identity = Identity::create([
            'email' => 'platform@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => true,
            'token_version' => 1,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'platform@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);
        $login->assertJsonPath('mode', 'platform');

        $me = $this->withAuthCookieFrom($login)->getJson('/api/platform/auth/me');
        $me->assertStatus(200);
        $me->assertJsonPath('user.id', $identity->id);
        $me->assertJsonPath('user.email', 'platform@test.test');
        $me->assertJsonPath('user.role', 'platform_admin');
        $me->assertJsonPath('tenant', null);

        $tenants = $this->withAuthCookieFrom($login)->getJson('/api/platform/tenants');
        $tenants->assertStatus(200);
    }

    /** @test */
    public function identity_token_with_not_platform_admin_returns_403(): void
    {
        $identity = Identity::create([
            'email' => 'tenantonly@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => true,
            'is_platform_admin' => false,
            'token_version' => 1,
        ]);
        $token = AuthToken::createForIdentity($identity, null, 'tenant_admin');

        $response = $this->withAuthCookie($token)->getJson('/api/platform/tenants');
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Not a platform admin.');
    }

    /** @test */
    public function identity_disabled_returns_403(): void
    {
        $identity = Identity::create([
            'email' => 'disabled@test.test',
            'password_hash' => Hash::make('secret'),
            'is_enabled' => false,
            'is_platform_admin' => true,
            'token_version' => 1,
        ]);
        $token = AuthToken::createForIdentity($identity, null, 'platform_admin');

        $response = $this->withAuthCookie($token)->getJson('/api/platform/tenants');
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Account is disabled.');
    }

    /** @test */
    public function platform_route_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/platform/tenants');
        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Authentication required');
    }
}
