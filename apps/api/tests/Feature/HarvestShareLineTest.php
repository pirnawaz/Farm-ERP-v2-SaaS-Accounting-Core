<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestShareLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HarvestShareLineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CropCycle $cropCycle;

    private Project $project;

    private InvItem $item;

    private InvStore $store;

    private function enableCropOps(Tenant $tenant): void
    {
        $m = Module::where('key', 'crop_ops')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function headers(string $role = 'accountant'): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Share Line Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableCropOps($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Party',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $this->store = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
    }

    public function test_can_add_update_delete_share_lines_on_draft_harvest(): void
    {
        $create = $this->withHeaders($this->headers())
            ->postJson('/api/v1/crop-ops/harvests', [
                'crop_cycle_id' => $this->cropCycle->id,
                'project_id' => $this->project->id,
                'harvest_date' => '2024-06-15',
            ]);
        $create->assertStatus(201);
        $harvestId = $create->json('id');

        $add = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 40,
            ]);
        $add->assertStatus(201);
        $this->assertCount(1, $add->json('share_lines'));
        $this->assertEquals(40, (float) $add->json('share_lines.0.share_value'));
        $lineId = $add->json('share_lines.0.id');

        $upd = $this->withHeaders($this->headers())
            ->putJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines/{$lineId}", [
                'share_value' => 55,
            ]);
        $upd->assertStatus(200);
        $this->assertEquals(55, (float) $upd->json('share_lines.0.share_value'));

        $del = $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines/{$lineId}");
        $del->assertStatus(204);

        $this->assertEquals(0, HarvestShareLine::where('harvest_id', $harvestId)->count());
    }

    public function test_cannot_modify_share_lines_when_harvest_reversed(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'REVERSED',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 10,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_modify_share_lines_when_harvest_posted(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'POSTED',
        ]);

        $add = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 10,
            ]);
        $add->assertStatus(422);

        $line = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 10,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $put = $this->withHeaders($this->headers())
            ->putJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines/{$line->id}", [
                'share_value' => 20,
            ]);
        $put->assertStatus(422);

        $del = $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines/{$line->id}");
        $del->assertStatus(422);
    }

    public function test_second_harvest_level_remainder_rejected(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_REMAINDER,
                'remainder_bucket' => true,
            ])
            ->assertStatus(201);

        $second = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_REMAINDER,
                'remainder_bucket' => true,
            ]);
        $second->assertStatus(422);
    }

    public function test_show_includes_share_line_relations(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 5,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $show = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}");
        $show->assertStatus(200);
        $show->assertJsonPath('share_lines.0.inventory_item.id', $this->item->id);
        $show->assertJsonPath('share_lines.0.store.id', $this->store->id);
    }
}
