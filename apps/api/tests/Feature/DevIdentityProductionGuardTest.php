<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevIdentityProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function when_dev_identity_disabled_platform_header_only_returns_401(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['auth.dev_identity_enabled' => false]);

        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders([
            'X-User-Id' => $user->id,
            'X-User-Role' => 'platform_admin',
        ])->getJson('/api/platform/tenants');

        $r->assertStatus(401);
        $r->assertJsonPath('error', 'Authentication required');
    }

    /** @test */
    public function when_dev_identity_disabled_tenant_header_only_returns_401(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['auth.dev_identity_enabled' => false]);

        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'a@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'X-User-Id' => $user->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/users');

        $r->assertStatus(401);
        $r->assertJsonPath('error', 'Authentication required');
    }

    /** @test */
    public function when_dev_identity_allowed_platform_accepts_headers(): void
    {
        $this->app->detectEnvironment(fn () => 'testing');
        config(['auth.dev_identity_enabled' => false]); // still allowed because env is testing

        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders([
            'X-User-Id' => $user->id,
            'X-User-Role' => 'platform_admin',
        ])->getJson('/api/platform/tenants');

        $r->assertStatus(200);
        $r->assertJsonStructure(['tenants']);
    }

    /** @test */
    public function when_dev_identity_allowed_tenant_accepts_headers(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config(['auth.dev_identity_enabled' => false]);

        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'a@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders([
            'X-Tenant-Id' => $tenant->id,
            'X-User-Id' => $user->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/users');

        $r->assertStatus(200);
    }

    /** @test */
    public function dev_identity_enabled_true_allows_headers_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['auth.dev_identity_enabled' => true]);

        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'p@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders([
            'X-User-Id' => $user->id,
            'X-User-Role' => 'platform_admin',
        ])->getJson('/api/platform/tenants');

        $r->assertStatus(200);
    }
}
