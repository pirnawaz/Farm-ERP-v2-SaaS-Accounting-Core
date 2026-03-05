<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantPlatformIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuthCookie(string $userId, ?string $tenantId, string $role): string
    {
        return base64_encode(json_encode([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'role' => $role,
            'email' => 'u@test.test',
            'expires_at' => now()->addDays(7)->timestamp,
        ]));
    }

    public function test_platform_admin_cookie_cannot_access_tenant_routes(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $platformUser = User::create([
            'tenant_id' => null,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => Hash::make('secret'),
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $cookie = $this->makeAuthCookie($platformUser->id, null, 'platform_admin');

        $response = $this->withCookie('farm_erp_auth_token', $cookie)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/tenant/users');

        $response->assertStatus(401);
    }

    public function test_tenant_user_cookie_with_different_tenant_id_gets_403(): void
    {
        $tA = Tenant::create(['name' => 'A', 'status' => 'active']);
        $tB = Tenant::create(['name' => 'B', 'status' => 'active']);
        $userA = User::create([
            'tenant_id' => $tA->id,
            'name' => 'User A',
            'email' => 'a@test.test',
            'password' => Hash::make('secret'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $cookie = $this->makeAuthCookie($userA->id, $tA->id, 'tenant_admin');

        $response = $this->withCookie('farm_erp_auth_token', $cookie)
            ->withHeader('X-Tenant-Id', $tB->id)
            ->getJson('/api/tenant/users');

        $this->assertTrue(in_array($response->status(), [401, 403], true), 'Cross-tenant must be denied (401 or 403)');
        if ($response->status() === 403) {
            $response->assertJsonPath('error', 'Cross-tenant access denied');
        }
    }
}
