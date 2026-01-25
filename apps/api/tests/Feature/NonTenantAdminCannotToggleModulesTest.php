<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NonTenantAdminCannotToggleModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_cannot_put_tenant_modules(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'land', 'enabled' => false]],
            ]);

        $response->assertStatus(403);
    }

    public function test_operator_cannot_put_tenant_modules(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'land', 'enabled' => false]],
            ]);

        $response->assertStatus(403);
    }
}
