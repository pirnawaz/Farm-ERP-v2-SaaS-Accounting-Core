<?php

namespace Tests\Feature\CropCycles;

use App\Models\CropCatalogItem;
use App\Models\CropCycle;
use App\Models\FieldBlock;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonSetupTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $tenantId, string $userId, string $role = 'tenant_admin'): array
    {
        return [
            'X-Tenant-Id' => $tenantId,
            'X-User-Id' => $userId,
            'X-User-Role' => $role,
        ];
    }

    public function test_season_setup_creates_allocation_and_project_per_field(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $module = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($module);
        TenantModule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $catalog = CropCatalogItem::first();
        $this->assertNotNull($catalog);
        $cropItem = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $catalog->id,
            'display_name' => 'Maize',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Season 2025',
            'tenant_crop_item_id' => $cropItem->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'OPEN',
        ]);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'North Field',
            'total_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers($tenant->id, $user->id))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", [
                'assignments' => [
                    [
                        'land_parcel_id' => $parcel->id,
                        'blocks' => [
                            ['tenant_crop_item_id' => $cropItem->id, 'area' => 50],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('crop_cycle_id', $cycle->id);
        $response->assertJsonPath('projects_created', 1);
        $projects = $response->json('projects');
        $this->assertCount(1, $projects);
        $this->assertStringContainsString('North Field', $projects[0]['name']);
        $this->assertStringContainsString('Maize', $projects[0]['name']);

        $this->assertDatabaseHas('land_allocations', [
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
        ]);
        $this->assertDatabaseHas('projects', [
            'crop_cycle_id' => $cycle->id,
            'name' => 'North Field – Maize',
        ]);
    }

    public function test_season_setup_returns_422_when_cycle_not_open(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $catalog = CropCatalogItem::first();
        $cropItem = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $catalog->id,
            'display_name' => 'Maize',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Closed Season',
            'tenant_crop_item_id' => $cropItem->id,
            'start_date' => '2024-01-01',
            'status' => 'CLOSED',
        ]);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'North',
            'total_acres' => 10,
        ]);

        $response = $this->withHeaders($this->headers($tenant->id, $user->id))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", [
                'assignments' => [
                    [
                        'land_parcel_id' => $parcel->id,
                        'blocks' => [['tenant_crop_item_id' => $cropItem->id]],
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['crop_cycle']);
    }

    public function test_season_setup_multi_block_creates_one_allocation_two_blocks_two_projects(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $module = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($module);
        TenantModule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $maizeCatalog = CropCatalogItem::where('code', 'MAIZE')->first();
        $wheatCatalog = CropCatalogItem::where('code', 'WHEAT')->first();
        $this->assertNotNull($maizeCatalog);
        $this->assertNotNull($wheatCatalog);
        $maize = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $maizeCatalog->id,
            'display_name' => 'Maize',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $wheat = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $wheatCatalog->id,
            'display_name' => 'Wheat',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Season 2025',
            'tenant_crop_item_id' => $maize->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'OPEN',
        ]);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'North Field',
            'total_acres' => 50,
        ]);

        $response = $this->withHeaders($this->headers($tenant->id, $user->id))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", [
                'assignments' => [
                    [
                        'land_parcel_id' => $parcel->id,
                        'blocks' => [
                            ['tenant_crop_item_id' => $maize->id, 'name' => 'North', 'area' => 20],
                            ['tenant_crop_item_id' => $wheat->id, 'name' => 'South', 'area' => 30],
                        ],
                    ],
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('crop_cycle_id', $cycle->id);
        $this->assertGreaterThanOrEqual(2, $response->json('field_blocks_created'));
        $this->assertSame(2, $response->json('projects_created'));
        $projects = $response->json('projects');
        $this->assertCount(2, $projects);

        $this->assertSame(1, LandAllocation::where('crop_cycle_id', $cycle->id)->where('land_parcel_id', $parcel->id)->count());
        $this->assertSame(2, FieldBlock::where('crop_cycle_id', $cycle->id)->where('land_parcel_id', $parcel->id)->count());
        $this->assertSame(2, Project::where('crop_cycle_id', $cycle->id)->whereNotNull('field_block_id')->count());

        $names = array_column($projects, 'name');
        $this->assertTrue(in_array('North Field – Maize (North)', $names, true));
        $this->assertTrue(in_array('North Field – Wheat (South)', $names, true));
    }

    public function test_season_setup_idempotent_repost_does_not_duplicate(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@t1.test',
            'password' => null,
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $module = Module::where('key', 'projects_crop_cycles')->first();
        $this->assertNotNull($module);
        TenantModule::firstOrCreate(
            ['tenant_id' => $tenant->id, 'module_id' => $module->id],
            ['status' => 'ENABLED', 'enabled_at' => now()]
        );

        $catalog = CropCatalogItem::first();
        $cropItem = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $catalog->id,
            'display_name' => 'Maize',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Season 2025',
            'tenant_crop_item_id' => $cropItem->id,
            'start_date' => '2025-01-01',
            'status' => 'OPEN',
        ]);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'East',
            'total_acres' => 10,
        ]);

        $payload = [
            'assignments' => [
                [
                    'land_parcel_id' => $parcel->id,
                    'blocks' => [['tenant_crop_item_id' => $cropItem->id, 'area' => 10]],
                ],
            ],
        ];

        $this->withHeaders($this->headers($tenant->id, $user->id))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload)
            ->assertStatus(201);

        $allocBefore = LandAllocation::where('crop_cycle_id', $cycle->id)->count();
        $blocksBefore = FieldBlock::where('crop_cycle_id', $cycle->id)->count();
        $projectsBefore = Project::where('crop_cycle_id', $cycle->id)->count();

        $this->withHeaders($this->headers($tenant->id, $user->id))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload)
            ->assertStatus(201);

        $this->assertSame($allocBefore, LandAllocation::where('crop_cycle_id', $cycle->id)->count());
        $this->assertSame($blocksBefore, FieldBlock::where('crop_cycle_id', $cycle->id)->count());
        $this->assertSame($projectsBefore, Project::where('crop_cycle_id', $cycle->id)->count());
    }
}
