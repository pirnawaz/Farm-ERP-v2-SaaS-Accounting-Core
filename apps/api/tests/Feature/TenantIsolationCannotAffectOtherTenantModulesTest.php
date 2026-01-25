<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationCannotAffectOtherTenantModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggling_module_in_tenant_a_does_not_affect_tenant_b(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);

        $landModule = Module::where('key', 'land')->first();
        $this->assertNotNull($landModule);

        // Ensure tenant B has land explicitly ENABLED
        TenantModule::create([
            'tenant_id' => $tenantB->id,
            'module_id' => $landModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);

        // As tenant A admin: disable land
        $putA = $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'land', 'enabled' => false]],
            ]);
        $putA->assertStatus(200);

        // Tenant A: land should be disabled
        $getA = $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $landA = collect($getA->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($landA);
        $this->assertFalse($landA['enabled']);

        // Tenant B: land should still be enabled (unchanged by A's toggle)
        $getB = $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $landB = collect($getB->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($landB);
        $this->assertTrue($landB['enabled']);
    }
}
