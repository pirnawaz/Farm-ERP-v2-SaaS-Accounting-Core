<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Party;
use App\Models\ProductionUnit;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionUnitIdValidationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);

        $requiredModuleKeys = ['projects_crop_cycles', 'ar_sales'];
        foreach ($requiredModuleKeys as $key) {
            $module = Module::where('key', $key)->first();
            $this->assertNotNull($module, $key . ' module must exist');
            TenantModule::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'module_id' => $module->id],
                ['status' => 'ENABLED', 'enabled_at' => now()]
            );
            TenantModule::firstOrCreate(
                ['tenant_id' => $this->otherTenant->id, 'module_id' => $module->id],
                ['status' => 'ENABLED', 'enabled_at' => now()]
            );
        }
    }

    private function headers(string $role, ?string $tenantId = null): array
    {
        return [
            'X-Tenant-Id' => $tenantId ?? $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    public function test_seasonal_workflow_can_create_sale_without_production_unit_id(): void
    {
        $buyer = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer A',
            'party_types' => ['BUYER'],
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/sales', [
                'buyer_party_id' => $buyer->id,
                'amount' => 1000,
                'posting_date' => '2026-01-15',
            ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('production_unit_id'));
    }

    public function test_production_unit_id_validation_is_tenant_scoped(): void
    {
        $buyer = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer A',
            'party_types' => ['BUYER'],
        ]);

        $otherTenantUnit = ProductionUnit::create([
            'tenant_id' => $this->otherTenant->id,
            'name' => 'Other tenant unit',
            'type' => 'LONG_CYCLE',
            'start_date' => '2025-01-01',
            'status' => 'ACTIVE',
        ]);

        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/sales', [
                'buyer_party_id' => $buyer->id,
                'amount' => 1000,
                'posting_date' => '2026-01-15',
                'production_unit_id' => $otherTenantUnit->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['production_unit_id']]);
    }
}

