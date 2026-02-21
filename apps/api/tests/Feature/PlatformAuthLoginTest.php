<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function platform_login_works_without_x_tenant_id_for_platform_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Platform Tenant', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform Admin',
            'email' => 'platform@test.test',
            'password' => Hash::make('secret'),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform@test.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('role', 'platform_admin');
        $response->assertJsonPath('is_platform_admin', true);
        $response->assertJsonPath('tenant_id', null);
        $response->assertCookie('farm_erp_auth_token');
    }

    /** @test */
    public function platform_login_rejects_non_platform_admin(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant Admin',
            'email' => 'admin@t1.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'admin@t1.test',
            'password' => 'secret',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Access denied. Platform admin role required.');
    }

    /** @test */
    public function platform_login_rejects_invalid_credentials(): void
    {
        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'nobody@test.test',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function platform_me_returns_is_platform_admin_without_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform Admin',
            'email' => 'platform@test.test',
            'password' => Hash::make('secret'),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform@test.test',
            'password' => 'secret',
        ]);
        $login->assertStatus(200);
        $body = $login->json();

        $me = $this->withHeaders([
            'X-User-Id' => $body['user_id'],
            'X-User-Role' => $body['role'],
        ])->getJson('/api/platform/auth/me');

        $me->assertStatus(200);
        $me->assertJsonPath('is_platform_admin', true);
        $me->assertJsonPath('user_id', $user->id);
        $me->assertJsonPath('email', 'platform@test.test');
    }
}
