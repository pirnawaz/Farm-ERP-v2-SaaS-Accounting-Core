<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCanToggleNonCoreModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Use ar_sales (optional, no dependents); Land cannot be disabled when core projects_crop_cycles requires it.
     */
    public function test_tenant_admin_can_disable_and_reenable_non_core_module(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $key = 'ar_sales';

        $putEnable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => $key, 'enabled' => true]],
            ]);
        $putEnable->assertStatus(200);
        $getAfterEnable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $mod = collect($getAfterEnable->json('modules'))->firstWhere('key', $key);
        $this->assertNotNull($mod);
        $this->assertTrue($mod['enabled']);

        $putDisable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => $key, 'enabled' => false]],
            ]);
        $putDisable->assertStatus(200);

        $getAfterDisable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $mod2 = collect($getAfterDisable->json('modules'))->firstWhere('key', $key);
        $this->assertNotNull($mod2);
        $this->assertFalse($mod2['enabled']);
        $this->assertEquals('DISABLED', $mod2['status']);

        $putReenable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => $key, 'enabled' => true]],
            ]);
        $putReenable->assertStatus(200);
        $getAfterReenable = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $mod3 = collect($getAfterReenable->json('modules'))->firstWhere('key', $key);
        $this->assertNotNull($mod3);
        $this->assertTrue($mod3['enabled']);
        $this->assertEquals('ENABLED', $mod3['status']);
    }
}
