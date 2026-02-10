<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCanListModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_list_modules(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('modules', $data);
        $this->assertIsArray($data['modules']);

        $keys = array_column($data['modules'], 'key');
        $coreKeys = ['accounting_core', 'projects_crop_cycles', 'reports', 'treasury_payments'];
        foreach ($coreKeys as $key) {
            $this->assertContains($key, $keys);
        }

        foreach ($coreKeys as $key) {
            $module = collect($data['modules'])->firstWhere('key', $key);
            $this->assertNotNull($module, "Core module {$key} should be in list");
            $this->assertTrue($module['enabled'], "Core module {$key} should be enabled");
            $this->assertEquals('ENABLED', $module['status']);
            $this->assertTrue($module['is_core'], "Module {$key} should be core");
        }

        $land = collect($data['modules'])->firstWhere('key', 'land');
        $this->assertNotNull($land);
        $this->assertFalse($land['is_core'], 'land should not be core');

        foreach ($data['modules'] as $m) {
            $this->assertArrayHasKey('key', $m);
            $this->assertArrayHasKey('name', $m);
            $this->assertArrayHasKey('description', $m);
            $this->assertArrayHasKey('is_core', $m);
            $this->assertArrayHasKey('sort_order', $m);
            $this->assertArrayHasKey('enabled', $m);
            $this->assertArrayHasKey('status', $m);
        }
    }
}
