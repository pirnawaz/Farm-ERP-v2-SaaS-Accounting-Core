<?php

namespace Tests\Feature;

use App\Models\CropCatalogItem;
use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CropItemsAndCropCycleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private TenantCropItem $tenantCropItem;
    private TenantCropItem $customCropItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Crop Items Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);

        $projectsModule = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($projectsModule, 'projects_crop_cycles module must exist');
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $projectsModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $projectsModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $catalog = CropCatalogItem::first(); // from data migration
        $this->assertNotNull($catalog, 'Crop catalog should be seeded by migration');
        $this->tenantCropItem = TenantCropItem::create([
            'tenant_id' => $this->tenant->id,
            'crop_catalog_item_id' => $catalog->id,
            'custom_name' => null,
            'display_name' => $catalog->default_name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->customCropItem = TenantCropItem::create([
            'tenant_id' => $this->tenant->id,
            'crop_catalog_item_id' => null,
            'custom_name' => 'My Custom Crop',
            'display_name' => 'My Custom Crop',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function headers(string $role, ?string $tenantId = null): array
    {
        return [
            'X-Tenant-Id' => $tenantId ?? $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    public function test_crop_items_list_returns_only_tenant_items(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->getJson('/api/crop-items');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $ids = array_column($data, 'id');
        $this->assertContains($this->tenantCropItem->id, $ids);
        $this->assertContains($this->customCropItem->id, $ids);
        $first = collect($data)->firstWhere('id', $this->tenantCropItem->id);
        $this->assertNotNull($first);
        $this->assertSame('global', $first['source']);
        $this->assertNotNull($first['catalog_code']);
        $customFirst = collect($data)->firstWhere('id', $this->customCropItem->id);
        $this->assertSame('custom', $customFirst['source']);
        $this->assertNull($customFirst['catalog_code']);
    }

    public function test_crop_items_list_other_tenant_does_not_see_first_tenant_items(): void
    {
        $otherItem = TenantCropItem::create([
            'tenant_id' => $this->otherTenant->id,
            'crop_catalog_item_id' => CropCatalogItem::first()->id,
            'display_name' => 'Maize',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $response = $this->withHeaders($this->headers('operator', $this->otherTenant->id))
            ->getJson('/api/crop-items');
        $response->assertStatus(200);
        $ids = array_column($response->json(), 'id');
        $this->assertContains($otherItem->id, $ids);
        $this->assertNotContains($this->tenantCropItem->id, $ids);
    }

    public function test_operator_can_get_crop_items_but_cannot_post(): void
    {
        $response = $this->withHeaders($this->headers('operator'))
            ->postJson('/api/crop-items', ['custom_name' => 'New Crop']);
        $response->assertStatus(403);
        $response->assertJsonPath('error', 'Insufficient permissions');
    }

    public function test_tenant_admin_can_create_custom_crop_item(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/crop-items', [
                'custom_name' => 'Local Maize',
                'display_name' => 'Local Maize Variety',
            ]);
        $response->assertStatus(201);
        $response->assertJsonPath('source', 'custom');
        $response->assertJsonPath('display_name', 'Local Maize Variety');
        $response->assertJsonPath('catalog_code', null);
    }

    public function test_accountant_can_update_crop_item_display_name(): void
    {
        $response = $this->withHeaders($this->headers('accountant'))
            ->patchJson("/api/crop-items/{$this->tenantCropItem->id}", [
                'display_name' => 'Renamed Maize',
            ]);
        $response->assertStatus(200);
        $response->assertJsonPath('display_name', 'Renamed Maize');
    }

    public function test_crop_cycle_create_requires_tenant_crop_item_id(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/crop-cycles', [
                'name' => '2025 Cycle',
                'start_date' => '2025-01-01',
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tenant_crop_item_id']);
    }

    public function test_crop_cycle_create_with_tenant_crop_item_id_succeeds(): void
    {
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->postJson('/api/crop-cycles', [
                'name' => '2025 Cycle',
                'tenant_crop_item_id' => $this->tenantCropItem->id,
                'start_date' => '2025-01-01',
            ]);
        $response->assertStatus(201);
        $response->assertJsonPath('name', '2025 Cycle');
        $response->assertJsonPath('tenant_crop_item_id', $this->tenantCropItem->id);
        $response->assertJsonPath('crop_display_name', $this->tenantCropItem->display_name ?: 'Maize');
    }

    public function test_crop_cycle_list_includes_crop_display_name(): void
    {
        CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Existing Cycle',
            'tenant_crop_item_id' => $this->tenantCropItem->id,
            'start_date' => '2024-01-01',
            'status' => 'OPEN',
        ]);
        $response = $this->withHeaders($this->headers('tenant_admin'))
            ->getJson('/api/crop-cycles');
        $response->assertStatus(200);
        $first = collect($response->json())->firstWhere('name', 'Existing Cycle');
        $this->assertNotNull($first);
        $this->assertArrayHasKey('crop_display_name', $first);
    }
}
