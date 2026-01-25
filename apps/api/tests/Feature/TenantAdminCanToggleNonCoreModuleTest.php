<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCanToggleNonCoreModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_disable_and_reenable_non_core_module(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $putDisable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'land', 'enabled' => false]],
            ]);
        $putDisable->assertStatus(200);

        $getAfterDisable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $getAfterDisable->assertStatus(200);
        $land = collect($getAfterDisable->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($land);
        $this->assertFalse($land['enabled']);
        $this->assertEquals('DISABLED', $land['status']);

        $putEnable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'land', 'enabled' => true]],
            ]);
        $putEnable->assertStatus(200);

        $getAfterEnable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $land2 = collect($getAfterEnable->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($land2);
        $this->assertTrue($land2['enabled']);
        $this->assertEquals('ENABLED', $land2['status']);
    }
}
