<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleLicensingEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function module_disabled_route_returns_403(): void
    {
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }

        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);
        // Do not enable inventory for this tenant

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/inventory/items');

        $response->assertStatus(403);
        $body = (string) $response->getContent();
        $this->assertStringContainsString('module not enabled', strtolower($body));
    }

    /** @test */
    public function module_enabled_route_allows_access(): void
    {
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }

        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);
        $module = Module::where('key', 'inventory')->first();
        $this->assertNotNull($module);
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $module->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/inventory/items');

        $response->assertStatus(200);
    }

    /** @test */
    public function core_module_cannot_be_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'reports', 'enabled' => false]],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'MODULE_DEPENDENCY', 'message' => 'Core modules cannot be disabled.']);
    }
}
