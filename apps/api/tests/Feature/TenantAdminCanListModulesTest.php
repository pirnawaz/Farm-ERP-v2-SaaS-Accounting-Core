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
        $this->assertContains('accounting_core', $keys);

        $accountingCore = collect($data['modules'])->firstWhere('key', 'accounting_core');
        $this->assertNotNull($accountingCore);
        $this->assertTrue($accountingCore['enabled']);
        $this->assertEquals('ENABLED', $accountingCore['status']);
        $this->assertTrue($accountingCore['is_core']);

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
