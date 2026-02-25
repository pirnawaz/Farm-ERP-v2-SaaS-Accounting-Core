<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\ProductionUnit;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionUnitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Production Unit Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);

        $module = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($module, 'projects_crop_cycles module must exist');
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
    }

    private function headers(string $role, ?string $tenantId = null): array
    {
        return [
            'X-Tenant-Id' => $tenantId ?? $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    public function test_index_returns_only_tenant_units(): void
    {
        ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sugarcane Plant 2025',
            'type' => 'LONG_CYCLE',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);
        ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Tenant Unit',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('Sugarcane Plant 2025', $data[0]['name']);
        $this->assertSame('LONG_CYCLE', $data[0]['type']);
    }

    public function test_index_filters_by_status_and_type(): void
    {
        ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Active Seasonal',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);
        ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Long',
            'type' => 'LONG_CYCLE',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units?status=ACTIVE');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame('Active Seasonal', $response->json()[0]['name']);

        $response2 = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units?type=LONG_CYCLE');
        $response2->assertStatus(200);
        $this->assertCount(1, $response2->json());
        $this->assertSame('Closed Long', $response2->json()[0]['name']);
    }

    public function test_store_creates_unit_scoped_to_tenant(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/production-units', [
                'name' => 'Sugarcane Plant 2025',
                'type' => 'LONG_CYCLE',
                'start_date' => '2025-01-01',
                'notes' => 'Multi-cycle cane',
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertArrayHasKey('id', $data);
        $this->assertSame($this->tenant->id, $data['tenant_id']);
        $this->assertSame('Sugarcane Plant 2025', $data['name']);
        $this->assertSame('LONG_CYCLE', $data['type']);
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertSame('Multi-cycle cane', $data['notes']);

        $this->assertDatabaseHas('production_units', [
            'id' => $data['id'],
            'tenant_id' => $this->tenant->id,
            'name' => 'Sugarcane Plant 2025',
        ]);
    }

    public function test_show_returns_404_for_other_tenant_unit(): void
    {
        $unit = ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Unit',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units/' . $unit->id);

        $response->assertStatus(404);
    }

    public function test_show_returns_unit_for_own_tenant(): void
    {
        $unit = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'My Unit',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units/' . $unit->id);

        $response->assertStatus(200);
        $this->assertSame('My Unit', $response->json('name'));
    }

    public function test_update_returns_404_for_other_tenant_unit(): void
    {
        $unit = ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other Unit',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson('/api/production-units/' . $unit->id, ['name' => 'Hacked']);

        $response->assertStatus(404);
    }

    public function test_update_modifies_unit(): void
    {
        $unit = ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original',
            'type' => 'SEASONAL',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson('/api/production-units/' . $unit->id, [
                'name' => 'Updated Name',
                'status' => 'CLOSED',
                'end_date' => '2025-12-31',
            ]);

        $response->assertStatus(200);
        $this->assertSame('Updated Name', $response->json('name'));
        $this->assertSame('CLOSED', $response->json('status'));
        $endDate = $response->json('end_date');
        $this->assertTrue(str_starts_with($endDate, '2025-12-31'), 'end_date should be 2025-12-31 (got: ' . $endDate . ')');
    }

    public function test_orchard_fields_can_be_stored_and_updated(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/production-units', [
                'name' => 'North Mango Block',
                'type' => 'LONG_CYCLE',
                'start_date' => '2020-01-01',
                'category' => 'ORCHARD',
                'orchard_crop' => 'Mango',
                'planting_year' => 2020,
                'area_acres' => 5.5,
                'tree_count' => 120,
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertSame('ORCHARD', $data['category']);
        $this->assertSame('Mango', $data['orchard_crop']);
        $this->assertSame(2020, $data['planting_year']);
        $this->assertSame('5.5000', $data['area_acres']);
        $this->assertSame(120, $data['tree_count']);

        $id = $data['id'];
        $patch = $this->withHeaders($this->headers('tenant_admin'))
            ->patchJson('/api/production-units/' . $id, [
                'orchard_crop' => 'Lemon',
                'tree_count' => 150,
            ]);
        $patch->assertStatus(200);
        $this->assertSame('Lemon', $patch->json('orchard_crop'));
        $this->assertSame(150, $patch->json('tree_count'));
    }

    public function test_index_filters_by_category(): void
    {
        ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Orchard Unit',
            'type' => 'LONG_CYCLE',
            'start_date' => '2022-01-01',
            'status' => 'ACTIVE',
            'category' => 'ORCHARD',
            'orchard_crop' => 'Phalsa',
        ]);
        ProductionUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sugarcane Unit',
            'type' => 'LONG_CYCLE',
            'start_date' => '2023-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/production-units?category=ORCHARD');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertSame('Orchard Unit', $data[0]['name']);
        $this->assertSame('ORCHARD', $data[0]['category']);
        $this->assertSame('Phalsa', $data[0]['orchard_crop']);
    }
}
