<?php

namespace Tests\Feature;

use App\Domains\Platform\Modules\ModuleDependencyService;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTenantModulesTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdminHeaders(string $userId): array
    {
        return [
            'X-User-Id' => $userId,
            'X-User-Role' => 'platform_admin',
        ];
    }

    /** @test */
    public function get_tenant_modules_requires_platform_admin(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);

        $r = $this->getJson('/api/platform/tenants/' . $tenant->id . '/modules');
        $r->assertStatus(401);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $r2 = $this->withHeaders(['X-User-Id' => $user->id, 'X-User-Role' => 'tenant_admin'])
            ->getJson('/api/platform/tenants/' . $tenant->id . '/modules');
        $r2->assertStatus(403);
    }

    /** @test */
    public function platform_admin_can_get_tenant_modules(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'starter']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->getJson('/api/platform/tenants/' . $tenant->id . '/modules');

        $r->assertStatus(200);
        $r->assertJsonPath('plan_key', 'starter');
        $this->assertArrayHasKey('modules', $r->json());
    }

    /** @test */
    public function put_tenant_modules_enables_allowed_and_rejects_disallowed_by_plan(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'starter']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $inventory = Module::where('key', 'inventory')->first();
        $this->assertNotNull($inventory, 'Module inventory should exist from seed');

        // starter plan does not include inventory; enabling should fail
        $put = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id . '/modules', [
                'modules' => [
                    ['key' => 'inventory', 'enabled' => true],
                ],
            ]);

        $put->assertStatus(422);
        $put->assertJsonPath('error', 'MODULE_NOT_ALLOWED_BY_PLAN');
        $this->assertStringContainsString('inventory', $put->json('message'));
        $this->assertStringContainsString('starter', $put->json('message'));
    }

    /** @test */
    public function changing_tenant_plan_disables_modules_not_allowed(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'enterprise']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $machinery = Module::where('key', 'machinery')->first();
        $this->assertNotNull($machinery, 'Module machinery should exist');
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $machinery->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);

        $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id, ['plan_key' => 'starter']);

        $tenant->refresh();
        $this->assertSame('starter', $tenant->plan_key);

        $pivot = TenantModule::where('tenant_id', $tenant->id)->where('module_id', $machinery->id)->first();
        $this->assertNotNull($pivot);
        $this->assertSame('DISABLED', $pivot->status);
    }

    /** @test */
    public function enabling_module_auto_enables_transitive_dependencies(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'enterprise']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $put = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id . '/modules', [
                'modules' => [
                    ['key' => 'crop_ops', 'enabled' => true],
                ],
            ]);

        $put->assertStatus(200);
        $modules = $put->json('modules');
        $byKey = collect($modules)->keyBy('key');

        $expectedEnabled = ['crop_ops', 'projects_crop_cycles', 'inventory', 'labour', 'land'];
        foreach ($expectedEnabled as $key) {
            $this->assertTrue($byKey->get($key)['enabled'] ?? false, "Module {$key} should be enabled");
        }
    }

    /** @test */
    public function module_dependency_service_returns_dependents_for_land(): void
    {
        $service = $this->app->make(ModuleDependencyService::class);
        $dependents = $service->getTransitiveDependents('land');
        $this->assertContains('projects_crop_cycles', $dependents);
    }

    /** @test */
    public function disabling_required_dependency_is_blocked_when_dependent_enabled(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'enterprise']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $projectsModule = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($projectsModule);
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $projectsModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);
        $landModule = Module::where('key', 'land')->first();
        $this->assertNotNull($landModule);
        TenantModule::create([
            'tenant_id' => $tenant->id,
            'module_id' => $landModule->id,
            'status' => 'ENABLED',
            'enabled_at' => now(),
        ]);

        $put = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id . '/modules', [
                'modules' => [
                    ['key' => 'projects_crop_cycles', 'enabled' => true],
                    ['key' => 'land', 'enabled' => false],
                ],
            ]);

        $put->assertStatus(422);
        $message = $put->json('message');
        $this->assertStringContainsString('Cannot disable land', $message);
        $this->assertStringContainsString('projects_crop_cycles', $message);
    }

    /** @test */
    public function plan_blocks_dependency_enabling_fails(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'starter']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $put = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id . '/modules', [
                'modules' => [
                    ['key' => 'crop_ops', 'enabled' => true],
                ],
            ]);

        $put->assertStatus(422);
        $message = $put->json('message');
        $this->assertStringContainsString('crop_ops', $message);
        $this->assertStringContainsString('starter', $message);
        $this->assertTrue(
            str_contains($message, 'dependency') && str_contains($message, 'inventory')
            || str_contains($message, 'not allowed on plan'),
            'Message should mention dependency+inventory or plan rejection'
        );
    }

    /** @test */
    public function core_module_cannot_be_disabled(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active', 'plan_key' => 'enterprise']);
        $platformUser = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Platform',
            'email' => 'platform@test.test',
            'password' => null,
            'role' => 'platform_admin',
            'is_enabled' => true,
        ]);

        $put = $this->withHeaders($this->platformAdminHeaders($platformUser->id))
            ->putJson('/api/platform/tenants/' . $tenant->id . '/modules', [
                'modules' => [
                    ['key' => 'accounting_core', 'enabled' => false],
                ],
            ]);

        $put->assertStatus(422);
        $message = $put->json('message');
        $this->assertStringContainsString('CORE', $message);
        $this->assertStringContainsString('cannot be disabled', $message);
    }
}
