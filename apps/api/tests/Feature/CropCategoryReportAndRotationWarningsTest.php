<?php

namespace Tests\Feature;

use App\Models\CropCatalogItem;
use App\Models\CropCycle;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CropCategoryReportAndRotationWarningsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Report Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);

        $projectsModule = Module::where('key', 'projects_crop_cycles')->first();
        $landModule = Module::where('key', 'land')->first();
        $this->assertNotNull($projectsModule);
        $this->assertNotNull($landModule);
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $projectsModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'module_id' => $landModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $projectsModule->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );
        TenantModule::firstOrCreate(
            ['tenant_id' => $this->otherTenant->id, 'module_id' => $landModule->id],
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

    private function createCycleWithCrop(Tenant $tenant, CropCatalogItem $catalog, string $name, string $startDate): CropCycle
    {
        $tci = TenantCropItem::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'crop_catalog_item_id' => $catalog->id,
            ],
            [
                'display_name' => $catalog->default_name,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
        return CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'tenant_crop_item_id' => $tci->id,
            'start_date' => $startDate,
            'end_date' => $startDate === '2023-01-01' ? '2023-12-31' : '2024-12-31',
            'status' => 'OPEN',
        ]);
    }

    public function test_crop_category_acres_returns_totals_by_category_and_by_crop(): void
    {
        $maize = CropCatalogItem::where('code', 'MAIZE')->first();
        $wheat = CropCatalogItem::where('code', 'WHEAT')->first();
        $this->assertNotNull($maize);
        $this->assertNotNull($wheat);

        $cycle1 = $this->createCycleWithCrop($this->tenant, $maize, '2024 Maize', '2024-01-01');
        $cycle2 = $this->createCycleWithCrop($this->tenant, $wheat, '2024 Wheat', '2024-01-01');
        $parcel1 = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 200]);
        $parcel2 = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P2', 'total_acres' => 100]);

        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cycle1->id,
            'land_parcel_id' => $parcel1->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cycle1->id,
            'land_parcel_id' => $parcel2->id,
            'party_id' => null,
            'allocated_acres' => 30,
        ]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cycle2->id,
            'land_parcel_id' => $parcel1->id,
            'party_id' => null,
            'allocated_acres' => 25,
        ]);

        $response = $this->withHeaders($this->headers('operator'))
            ->getJson('/api/reports/crop-category-acres');
        $response->assertStatus(200);
        $data = $response->json();

        $byCategory = collect($data['totals_by_category']);
        $cereal = $byCategory->firstWhere('category', 'cereal');
        $this->assertNotNull($cereal);
        $this->assertEquals('105.00', $cereal['acres']); // 50+30+25

        $byCrop = collect($data['totals_by_crop']);
        $maizeRow = $byCrop->firstWhere('code', 'MAIZE');
        $wheatRow = $byCrop->firstWhere('code', 'WHEAT');
        $this->assertNotNull($maizeRow);
        $this->assertNotNull($wheatRow);
        $this->assertEquals('80.00', $maizeRow['acres']);
        $this->assertEquals('25.00', $wheatRow['acres']);
    }

    public function test_crop_category_acres_is_tenant_scoped(): void
    {
        $catalog = CropCatalogItem::first();
        $this->assertNotNull($catalog);
        $cycle = $this->createCycleWithCrop($this->otherTenant, $catalog, 'Other Cycle', '2024-01-01');
        $parcel = LandParcel::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Other P', 'total_acres' => 50]);
        LandAllocation::create([
            'tenant_id' => $this->otherTenant->id,
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers('operator'))
            ->getJson('/api/reports/crop-category-acres');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEmpty($data['totals_by_category']);
        $this->assertEmpty($data['totals_by_crop']);
    }

    public function test_rotation_warnings_returns_empty_when_no_prior_allocation(): void
    {
        $catalog = CropCatalogItem::first();
        $cycle = $this->createCycleWithCrop($this->tenant, $catalog, '2024', '2024-01-01');
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);

        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$parcel->id}/rotation-warnings?crop_cycle_id={$cycle->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('warnings', []);
    }

    public function test_rotation_warnings_returns_same_crop_consecutive_when_prior_cycle_same_crop(): void
    {
        $maize = CropCatalogItem::where('code', 'MAIZE')->first();
        $this->assertNotNull($maize);
        $cyclePrior = $this->createCycleWithCrop($this->tenant, $maize, '2023', '2023-01-01');
        $cycleCurrent = $this->createCycleWithCrop($this->tenant, $maize, '2024', '2024-01-01');
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cyclePrior->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$parcel->id}/rotation-warnings?crop_cycle_id={$cycleCurrent->id}");
        $response->assertStatus(200);
        $warnings = $response->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertEquals('SAME_CROP_CONSECUTIVE', $warnings[0]['code']);
        $this->assertEquals('warning', $warnings[0]['severity']);
    }

    public function test_rotation_warnings_returns_same_category_consecutive_when_prior_different_crop_same_category(): void
    {
        $maize = CropCatalogItem::where('code', 'MAIZE')->first();
        $wheat = CropCatalogItem::where('code', 'WHEAT')->first();
        $this->assertNotNull($maize);
        $this->assertNotNull($wheat);
        $cyclePrior = $this->createCycleWithCrop($this->tenant, $maize, '2023', '2023-01-01');
        $cycleCurrent = $this->createCycleWithCrop($this->tenant, $wheat, '2024', '2024-01-01');
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        LandAllocation::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $cyclePrior->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$parcel->id}/rotation-warnings?crop_cycle_id={$cycleCurrent->id}");
        $response->assertStatus(200);
        $warnings = $response->json('warnings');
        $this->assertCount(1, $warnings);
        $this->assertEquals('SAME_CATEGORY_CONSECUTIVE', $warnings[0]['code']);
    }

    public function test_rotation_warnings_requires_crop_cycle_id(): void
    {
        $parcel = LandParcel::create(['tenant_id' => $this->tenant->id, 'name' => 'P1', 'total_acres' => 100]);
        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$parcel->id}/rotation-warnings");
        $response->assertStatus(422);
    }

    public function test_rotation_warnings_returns_404_for_other_tenant_parcel(): void
    {
        $parcel = LandParcel::create(['tenant_id' => $this->otherTenant->id, 'name' => 'Other P', 'total_acres' => 100]);
        $catalog = CropCatalogItem::first();
        $cycle = $this->createCycleWithCrop($this->tenant, $catalog, '2024', '2024-01-01');
        $response = $this->withHeaders($this->headers('operator'))
            ->getJson("/api/land-parcels/{$parcel->id}/rotation-warnings?crop_cycle_id={$cycle->id}");
        $response->assertStatus(404);
    }
}
