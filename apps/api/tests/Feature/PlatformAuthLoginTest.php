<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\MakesAuthenticatedRequests;

class PlatformAuthLoginTest extends TestCase
{
    use RefreshDatabase;
    use MakesAuthenticatedRequests;

    /** @test */
    public function platform_login_works_without_x_tenant_id_for_platform_admin(): void
    {
        $user = User::create([
            'tenant_id' => null,
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
        $response->assertJsonPath('user.role', 'platform_admin');
        $response->assertJsonPath('tenant', null);
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
        $user = User::create([
            'tenant_id' => null,
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
        $this->assertNotEmpty($login->json('token'));

        $me = $this->withAuthCookieFrom($login)->getJson('/api/platform/auth/me');

        $me->assertStatus(200);
        $me->assertJsonPath('user.id', $user->id);
        $me->assertJsonPath('user.email', 'platform@test.test');
        $me->assertJsonPath('tenant', null);
    }
}
