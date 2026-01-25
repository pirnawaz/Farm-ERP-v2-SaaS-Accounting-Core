<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserAdminLastAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_disable_last_tenant_admin(): void
    {
        $t = Tenant::create(['name' => 'T1']);
        $admin = User::create(['tenant_id' => $t->id, 'name' => 'A', 'email' => 'a@x.com', 'role' => 'tenant_admin']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'tenant_admin',
        ])->putJson('/api/tenant/users/' . $admin->id, ['is_enabled' => false]);

        $response->assertStatus(422);
        $msg = $response->json('message') ?? '';
        $this->assertStringContainsString('last', strtolower($msg));
    }

    public function test_cannot_delete_last_tenant_admin(): void
    {
        $t = Tenant::create(['name' => 'T1']);
        $admin = User::create(['tenant_id' => $t->id, 'name' => 'A', 'email' => 'a@x.com', 'role' => 'tenant_admin']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'tenant_admin',
        ])->deleteJson('/api/tenant/users/' . $admin->id);

        $response->assertStatus(422);
    }

    public function test_can_disable_tenant_admin_when_another_exists(): void
    {
        $t = Tenant::create(['name' => 'T1']);
        $a1 = User::create(['tenant_id' => $t->id, 'name' => 'A1', 'email' => 'a1@x.com', 'role' => 'tenant_admin']);
        User::create(['tenant_id' => $t->id, 'name' => 'A2', 'email' => 'a2@x.com', 'role' => 'tenant_admin']);

        $response = $this->withHeaders([
            'X-Tenant-Id' => $t->id,
            'X-User-Role' => 'tenant_admin',
        ])->putJson('/api/tenant/users/' . $a1->id, ['is_enabled' => false]);

        $response->assertStatus(200);
        $a1->refresh();
        $this->assertFalse($a1->is_enabled);
    }
}
