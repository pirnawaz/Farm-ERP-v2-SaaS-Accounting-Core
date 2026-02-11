<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
    }

    public function test_tenant_admin_can_get_onboarding_state(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/onboarding');

        $response->assertStatus(200);
        $response->assertJsonStructure(['dismissed', 'steps']);
        $this->assertFalse($response->json('dismissed'));
        $steps = $response->json('steps');
        $this->assertArrayHasKey('farm_profile', $steps);
        $this->assertArrayHasKey('add_land_parcel', $steps);
        $this->assertArrayHasKey('create_crop_cycle', $steps);
        $this->assertArrayHasKey('create_first_project', $steps);
        $this->assertArrayHasKey('add_first_party', $steps);
        $this->assertArrayHasKey('post_first_transaction', $steps);
    }

    public function test_tenant_admin_can_dismiss_onboarding(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/onboarding', ['dismissed' => true]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('dismissed'));

        $get = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/tenant/onboarding');
        $get->assertStatus(200);
        $this->assertTrue($get->json('dismissed'));
    }

    public function test_tenant_admin_can_reopen_onboarding(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $tenant->update(['settings' => ['onboarding' => ['dismissed' => true, 'steps' => []]]]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->putJson('/api/tenant/onboarding', ['dismissed' => false]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('dismissed'));
    }
}
