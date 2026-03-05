<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantLoginRequiresTenantHeaderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function login_without_tenant_identifier_uses_unified_login_returns_401_for_invalid_credentials(): void
    {
        // Unified login: no tenant header → identity-based login; invalid credentials → 401
        $response = $this->postJson('/api/auth/login', [
            'email' => 'any@test.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error', 'Invalid credentials');
    }

    /** @test */
    public function tenant_login_with_invalid_uuid_returns_404(): void
    {
        $response = $this->withHeader('X-Tenant-Id', '550e8400-e29b-41d4-a716-446655440000')
            ->postJson('/api/auth/login', [
                'email' => 'any@test.test',
                'password' => 'secret',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'Tenant not found');
    }

    /** @test */
    public function tenant_login_with_valid_slug_works(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Farm', 'slug' => 'acme-farm', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@acme.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->withHeader('X-Tenant-Slug', 'acme-farm')
            ->postJson('/api/auth/login', [
                'email' => 'u@acme.test',
                'password' => 'secret',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'tenant_admin');
        $response->assertJsonPath('tenant.slug', 'acme-farm');
    }

    /** @test */
    public function tenant_login_with_valid_id_works(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@acme.test',
            'password' => Hash::make('secret'),
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/auth/login', [
                'email' => 'u@acme.test',
                'password' => 'secret',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('tenant.id', $tenant->id);
    }

    /** @test */
    public function platform_admin_cannot_authenticate_via_tenant_login(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'status' => 'active']);
        User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => Hash::make('secret'),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson('/api/auth/login', [
                'email' => 'platform@test.test',
                'password' => 'secret',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Use platform admin login for this account');
    }

    /** @test */
    public function tenant_user_cannot_authenticate_via_platform_login(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'slug' => 't1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant User',
            'email' => 'tenant@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'tenant@test.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Access denied. Platform admin role required.');
    }

    /** @test */
    public function inactive_tenant_returns_403_on_tenant_route(): void
    {
        $tenant = Tenant::create(['name' => 'Suspended', 'slug' => 'suspended', 'status' => 'suspended']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'User',
            'email' => 'u@suspended.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->withHeader('X-Tenant-Slug', 'suspended')
            ->postJson('/api/auth/login', [
                'email' => 'u@suspended.test',
                'password' => 'secret',
            ]);

        $response->assertStatus(403);
    }
}
