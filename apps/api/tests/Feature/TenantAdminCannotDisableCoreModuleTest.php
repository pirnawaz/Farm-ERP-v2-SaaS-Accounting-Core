<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAdminCannotDisableCoreModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_cannot_disable_core_module(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'accounting_core', 'enabled' => false]],
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Core modules cannot be disabled.']);

        $get = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/modules');
        $get->assertStatus(200);
        $core = collect($get->json('modules'))->firstWhere('key', 'accounting_core');
        $this->assertNotNull($core);
        $this->assertTrue($core['enabled']);
    }
}
