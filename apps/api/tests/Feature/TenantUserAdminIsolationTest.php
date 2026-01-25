<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserAdminIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_sees_only_own_tenant_users(): void
    {
        $tA = Tenant::create(['name' => 'A']);
        $tB = Tenant::create(['name' => 'B']);
        User::create(['tenant_id' => $tA->id, 'name' => 'UA', 'email' => 'ua@x.com', 'role' => 'tenant_admin']);
        User::create(['tenant_id' => $tB->id, 'name' => 'UB', 'email' => 'ub@x.com', 'role' => 'tenant_admin']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $tA->id,
            'X-User-Role' => 'tenant_admin',
        ])->getJson('/api/tenant/users');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('ua@x.com', $data[0]['email']);
    }

    public function test_tenant_admin_cannot_update_or_disable_user_from_other_tenant(): void
    {
        $tA = Tenant::create(['name' => 'A']);
        $tB = Tenant::create(['name' => 'B']);
        $userB = User::create(['tenant_id' => $tB->id, 'name' => 'UB', 'email' => 'ub@x.com', 'role' => 'operator']);

        $headers = ['X-Tenant-Id' => $tA->id, 'X-User-Role' => 'tenant_admin'];

        $r1 = $this->withHeaders($headers)
            ->putJson('/api/tenant/users/' . $userB->id, ['is_enabled' => false]);
        $r1->assertStatus(404);

        $r2 = $this->withHeaders($headers)
            ->deleteJson('/api/tenant/users/' . $userB->id);
        $r2->assertStatus(404);
    }
}
