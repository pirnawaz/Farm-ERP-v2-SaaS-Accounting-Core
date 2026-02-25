<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FarmIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_farm_integrity_returns_401_when_no_role(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/internal/farm-integrity');

        $response->assertStatus(401);
    }

    public function test_farm_integrity_returns_403_for_non_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/internal/farm-integrity');

        $response->assertStatus(403);
    }

    public function test_farm_integrity_returns_400_when_no_tenant(): void
    {
        $response = $this->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/internal/farm-integrity');

        $response->assertStatus(400);
    }

    public function test_farm_integrity_returns_200_with_all_keys_for_tenant_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/internal/farm-integrity');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('activities_missing_production_unit', $data);
        $this->assertArrayHasKey('harvest_without_sale', $data);
        $this->assertArrayHasKey('sales_overdue_no_payment', $data);
        $this->assertArrayHasKey('negative_inventory_items', $data);
        $this->assertArrayHasKey('production_units_no_activity_last_30_days', $data);
        $this->assertArrayHasKey('livestock_units_negative_headcount', $data);
        $this->assertIsInt($data['activities_missing_production_unit']);
        $this->assertIsInt($data['harvest_without_sale']);
        $this->assertIsInt($data['sales_overdue_no_payment']);
        $this->assertIsInt($data['negative_inventory_items']);
        $this->assertIsInt($data['production_units_no_activity_last_30_days']);
        $this->assertIsInt($data['livestock_units_negative_headcount']);
    }

    public function test_daily_admin_review_returns_200_for_tenant_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/internal/daily-admin-review');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('records_created_today', $data);
        $this->assertArrayHasKey('records_edited_today', $data);
        $this->assertIsInt($data['records_created_today']);
        $this->assertIsInt($data['records_edited_today']);
    }
}
