<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformTenantTest extends TestCase
{
    use RefreshDatabase;

    private function platformHeaders(): array
    {
        return ['X-User-Role' => 'platform_admin'];
    }

    public function test_platform_admin_can_list_tenants(): void
    {
        Tenant::create(['name' => 'T1']);
        Tenant::create(['name' => 'T2']);

        $response = $this->withHeaders($this->platformHeaders())
            ->getJson('/api/platform/tenants');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('tenants', $data);
        $this->assertCount(2, $data['tenants']);
    }

    public function test_platform_admin_can_create_tenant_with_initial_admin(): void
    {
        $payload = [
            'name' => 'New Farm',
            'country' => 'GB',
            'initial_admin_email' => 'admin@farm.test',
            'initial_admin_password' => 'password123',
            'initial_admin_name' => 'Admin User',
        ];

        $response = $this->withHeaders($this->platformHeaders())
            ->postJson('/api/platform/tenants', $payload);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertArrayHasKey('tenant', $data);
        $this->assertEquals('New Farm', $data['tenant']['name']);
        $this->assertEquals('active', $data['tenant']['status']);

        $this->assertDatabaseHas('tenants', ['name' => 'New Farm']);
        $this->assertDatabaseHas('farms', ['farm_name' => 'New Farm']);
        $this->assertDatabaseHas('users', ['email' => 'admin@farm.test', 'role' => 'tenant_admin']);
    }

    public function test_platform_admin_can_update_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'T1']);

        $response = $this->withHeaders($this->platformHeaders())
            ->putJson('/api/platform/tenants/' . $tenant->id, ['name' => 'Updated', 'status' => 'suspended']);

        $response->assertStatus(200);
        $this->assertEquals('Updated', $response->json('name'));
        $this->assertEquals('suspended', $response->json('status'));
        $tenant->refresh();
        $this->assertEquals('Updated', $tenant->name);
        $this->assertEquals('suspended', $tenant->status);
    }
}
