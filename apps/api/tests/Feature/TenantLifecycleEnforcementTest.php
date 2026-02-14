<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantLifecycleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function active_tenant_can_access_protected_route(): void
    {
        $tenant = Tenant::create(['name' => 'Active Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/parties');

        $response->assertStatus(200);
        $this->assertIsArray($response->json());
    }

    /** @test */
    public function suspended_tenant_cannot_access_protected_route(): void
    {
        $tenant = Tenant::create(['name' => 'Suspended Tenant', 'status' => 'suspended']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/parties');

        $response->assertStatus(403);
        $body = $response->getContent();
        $this->assertStringContainsString('tenant suspended', strtolower($body));
    }

    /** @test */
    public function tenant_a_cannot_see_tenant_b_data_on_protected_route(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);
        Party::create(['tenant_id' => $tenantA->id, 'name' => 'Party A', 'party_types' => ['HARI']]);
        Party::create(['tenant_id' => $tenantB->id, 'name' => 'Party B', 'party_types' => ['HARI']]);

        $response = $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/parties');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('Party A', $data[0]['name']);
    }
}
