<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleDependenciesEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_projects_crop_cycles_auto_enables_land(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $landModule = Module::where('key', 'land')->firstOrFail();
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $landModule->id,
            'status' => 'DISABLED',
            'enabled_at' => null,
            'disabled_at' => now(),
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'projects_crop_cycles', 'enabled' => true]],
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $modules = collect($data['modules']);
        $this->assertTrue($modules->firstWhere('key', 'projects_crop_cycles')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'land')['enabled'], 'Land must be auto-enabled when projects_crop_cycles is enabled');
        $this->assertArrayHasKey('auto_enabled', $data);
        if (isset($data['auto_enabled']['projects_crop_cycles'])) {
            $this->assertContains('land', $data['auto_enabled']['projects_crop_cycles']);
        }
    }

    public function test_disabling_land_while_projects_crop_cycles_enabled_fails_with_clear_error(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $projectsModule = Module::where('key', 'projects_crop_cycles')->firstOrFail();
        $landModule = Module::where('key', 'land')->firstOrFail();
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $projectsModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
            'disabled_at' => null,
        ]);
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $landModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
            'disabled_at' => null,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [
                    ['key' => 'land', 'enabled' => false],
                    ['key' => 'projects_crop_cycles', 'enabled' => true],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'error' => 'MODULE_DEPENDENCY',
            'blockers' => ['projects_crop_cycles'],
        ]);
        $message = $response->json('message');
        $this->assertStringContainsString('projects_crop_cycles', $message);
        $this->assertStringContainsString('depend', $message);
    }

    public function test_enabling_crop_ops_auto_enables_projects_crop_cycles_inventory_labour(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        foreach (['land', 'projects_crop_cycles', 'inventory', 'labour'] as $key) {
            $m = Module::where('key', $key)->firstOrFail();
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_id' => $m->id,
                'status' => 'DISABLED',
                'enabled_at' => null,
                'disabled_at' => now(),
            ]);
        }

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'crop_ops', 'enabled' => true]],
            ]);

        $response->assertStatus(200);
        $modules = collect($response->json('modules'));
        $this->assertTrue($modules->firstWhere('key', 'crop_ops')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'projects_crop_cycles')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'land')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'inventory')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'labour')['enabled']);
        $autoEnabled = $response->json('auto_enabled');
        $this->assertArrayHasKey('crop_ops', $autoEnabled);
        $alsoEnabled = $autoEnabled['crop_ops'];
        $this->assertGreaterThanOrEqual(1, count($alsoEnabled), 'crop_ops should have auto-enabled at least one dependency');
        $expectedDeps = ['land', 'inventory', 'labour'];
        $found = array_intersect($expectedDeps, $alsoEnabled);
        $this->assertNotEmpty($found, 'auto_enabled for crop_ops should include at least one of: ' . implode(', ', $expectedDeps));
    }

    public function test_core_modules_cannot_be_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'accounting_core', 'enabled' => false]],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'MODULE_DEPENDENCY']);
    }

    public function test_transitive_dependency_enabling_settlements_auto_enables_projects_crop_cycles_and_land(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        foreach (['land', 'projects_crop_cycles', 'settlements'] as $key) {
            $m = Module::where('key', $key)->firstOrFail();
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_id' => $m->id,
                'status' => 'DISABLED',
                'enabled_at' => null,
                'disabled_at' => now(),
            ]);
        }

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'settlements', 'enabled' => true]],
            ]);

        $response->assertStatus(200);
        $modules = collect($response->json('modules'));
        $this->assertTrue($modules->firstWhere('key', 'settlements')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'projects_crop_cycles')['enabled']);
        $this->assertTrue($modules->firstWhere('key', 'land')['enabled']);
    }

    public function test_index_returns_tier_and_required_by(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $projectsModule = Module::where('key', 'projects_crop_cycles')->firstOrFail();
        $landModule = Module::where('key', 'land')->firstOrFail();
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $projectsModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
            'disabled_at' => null,
        ]);
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $landModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
            'disabled_at' => null,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');

        $response->assertStatus(200);
        $land = collect($response->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($land);
        $this->assertArrayHasKey('tier', $land);
        $this->assertArrayHasKey('required_by', $land);
        $this->assertEquals('CORE_ADJUNCT', $land['tier']);
        $this->assertContains('projects_crop_cycles', $land['required_by']);
    }

    /**
     * New tenant with NO tenant_modules rows: projects_crop_cycles is core (effectively enabled).
     * Land is a hard dependency of projects_crop_cycles. Index must return Land as ENABLED and
     * required_by projects_crop_cycles; self-heal must persist tenant_modules for land only.
     */
    public function test_effective_enabled_land_when_core_projects_has_no_row(): void
    {
        // TEMP: skip when force-all-modules is on (self-heal transaction is skipped in override mode).
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $landModule = Module::where('key', 'land')->firstOrFail();

        $this->assertNull(
            TenantModule::where('tenant_id', $tenant->id)->where('module_id', $landModule->id)->first(),
            'Precondition: no tenant_modules row for land'
        );

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');

        $response->assertStatus(200);
        $land = collect($response->json('modules'))->firstWhere('key', 'land');
        $this->assertNotNull($land);
        $this->assertTrue($land['enabled'], 'Land must be effectively enabled when required by core Projects & Crop Cycles');
        $this->assertEquals('ENABLED', $land['status']);
        $this->assertContains('projects_crop_cycles', $land['required_by']);

        $pivot = TenantModule::where('tenant_id', $tenant->id)->where('module_id', $landModule->id)->first();
        $this->assertNotNull($pivot, 'Self-heal must create tenant_modules row for land');
        $this->assertEquals('ENABLED', $pivot->status);
    }

    /**
     * Self-heal only enables dependencies of effectively enabled modules.
     * treasury_payments is core; treasury_advances is optional and depends on treasury_payments.
     * We must NOT enable treasury_advances just because treasury_payments is core.
     */
    public function test_self_heal_does_not_enable_optional_deps(): void
    {
        // TEMP: skip when force-all-modules is on (override returns all modules as enabled).
        if (filter_var(env('FORCE_ALL_MODULES_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Skip when FORCE_ALL_MODULES_ENABLED is set.');
        }
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $advancesModule = Module::where('key', 'treasury_advances')->firstOrFail();

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');

        $response->assertStatus(200);
        $advances = collect($response->json('modules'))->firstWhere('key', 'treasury_advances');
        $this->assertNotNull($advances);
        $this->assertFalse($advances['enabled'], 'treasury_advances must remain disabled when not explicitly enabled');
        $this->assertEquals('DISABLED', $advances['status']);

        $pivot = TenantModule::where('tenant_id', $tenant->id)->where('module_id', $advancesModule->id)->first();
        $this->assertNull($pivot, 'Self-heal must NOT create tenant_modules for optional treasury_advances');
    }

    /**
     * Core modules must not get tenant_modules rows; they stay implicit.
     */
    public function test_no_tenant_modules_rows_for_core_modules_after_index(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $coreKeys = ['accounting_core', 'projects_crop_cycles', 'treasury_payments', 'reports'];
        $coreModuleIds = Module::whereIn('key', $coreKeys)->pluck('id')->all();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');

        foreach ($coreModuleIds as $moduleId) {
            $pivot = TenantModule::where('tenant_id', $tenant->id)->where('module_id', $moduleId)->first();
            $this->assertNull($pivot, "Must not create tenant_modules row for core module id {$moduleId}");
        }
    }
}
