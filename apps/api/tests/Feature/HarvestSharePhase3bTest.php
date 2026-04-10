<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Harvest share lines, preview, and (Phase 3C) share-aware posting regression tests.
 *
 * @see docs/phase-3a-2-harvest-share-inventory-valuation.md (rounding / last-bucket value)
 */
class HarvestSharePhase3bTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CropCycle $cropCycle;

    private Project $project;

    private InvItem $item;

    private InvStore $store;

    private Account $cropWipAccount;

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

    /**
     * Same pattern as {@see HarvestTest::createWipCost()} — net CROP_WIP for preview/post tests.
     */
    private function createWipCost(float $amount): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => Str::uuid()->toString(),
            'posting_date' => '2024-05-01',
            'idempotency_key' => 'wip-phase3b-'.uniqid(),
        ]);

        $cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->cropWipAccount->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Phase 3B Harvest Tenant', 'status' => 'active']);
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

        $this->cropWipAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CROP_WIP')->first();
    }

    public function test_can_create_draft_harvest_share_lines(): void
    {
        $pgCountBefore = PostingGroup::count();
        $mvCountBefore = InvStockMovement::count();

        $create = $this->withHeaders($this->headers())
            ->postJson('/api/v1/crop-ops/harvests', [
                'crop_cycle_id' => $this->cropCycle->id,
                'project_id' => $this->project->id,
                'harvest_date' => '2024-06-15',
            ]);
        $create->assertStatus(201);
        $harvestId = $create->json('id');
        $this->assertEquals('DRAFT', $create->json('status'));

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/lines", [
                'inventory_item_id' => $this->item->id,
                'store_id' => $this->store->id,
                'quantity' => 10,
            ])->assertStatus(201);

        $otherTenant = Tenant::create(['name' => 'Other Tenant Phase3B', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($otherTenant->id);
        $otherStore = InvStore::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 50,
                'store_id' => $otherStore->id,
                'inventory_item_id' => $this->item->id,
            ])->assertStatus(422);

        $add = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 40,
            ]);
        $add->assertStatus(201);
        $this->assertCount(1, $add->json('share_lines'));
        $this->assertDatabaseHas('harvest_share_lines', [
            'harvest_id' => $harvestId,
            'tenant_id' => $this->tenant->id,
            'share_value' => '40.000000',
        ]);

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvestId}/share-preview?posting_date=2024-06-15")
            ->assertStatus(200);

        $this->assertEquals($pgCountBefore, PostingGroup::count(), 'Preview must not create posting groups');
        $this->assertEquals($mvCountBefore, InvStockMovement::count(), 'Preview must not create stock movements');
    }

    public function test_can_update_draft_harvest_share_lines(): void
    {
        $harvestId = $this->withHeaders($this->headers())
            ->postJson('/api/v1/crop-ops/harvests', [
                'crop_cycle_id' => $this->cropCycle->id,
                'project_id' => $this->project->id,
                'harvest_date' => '2024-06-15',
            ])->assertStatus(201)
            ->json('id');

        $lineId = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 30,
            ])->assertStatus(201)
            ->json('share_lines.0.id');

        $upd = $this->withHeaders($this->headers())
            ->putJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines/{$lineId}", [
                'share_value' => 62.5,
            ]);
        $upd->assertStatus(200);
        $this->assertEquals(62.5, (float) $upd->json('share_lines.0.share_value'));
    }

    public function test_can_delete_draft_harvest_share_lines(): void
    {
        $harvestId = $this->withHeaders($this->headers())
            ->postJson('/api/v1/crop-ops/harvests', [
                'crop_cycle_id' => $this->cropCycle->id,
                'project_id' => $this->project->id,
                'harvest_date' => '2024-06-15',
            ])->assertStatus(201)
            ->json('id');

        $lineId = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 10,
            ])->assertStatus(201)
            ->json('share_lines.0.id');

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/crop-ops/harvests/{$harvestId}/share-lines/{$lineId}")
            ->assertStatus(204);

        $this->assertEquals(0, HarvestShareLine::where('harvest_id', $harvestId)->count());
    }

    public function test_posted_harvest_share_lines_cannot_be_modified(): void
    {
        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'POSTED',
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines", [
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => 10,
            ])->assertStatus(422);

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

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines/{$line->id}", ['share_value' => 20])
            ->assertStatus(422);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-lines/{$line->id}")
            ->assertStatus(422);
    }

    public function test_reversed_harvest_share_lines_cannot_be_modified(): void
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
            ])->assertStatus(422);
    }

    public function test_harvest_share_preview_supports_fixed_qty(): void
    {
        $this->createWipCost(500.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-FIX',
            'name' => 'Harvester',
            'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'machine_id' => $machine->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");

        $res->assertStatus(200);
        $this->assertEquals(500.0, (float) $res->json('total_wip_cost'));

        $machineBucket = collect($res->json('share_buckets'))->firstWhere('recipient_role', HarvestShareLine::RECIPIENT_MACHINE);
        $implicit = collect($res->json('share_buckets'))->firstWhere('implicit_owner', true);

        $this->assertEquals(1.0, (float) $machineBucket['computed_qty']);
        $this->assertEquals(50.0, (float) $machineBucket['provisional_value']);
        $this->assertEquals(9.0, (float) $implicit['computed_qty']);
        $this->assertEquals(450.0, (float) $implicit['provisional_value']);

        $this->assertEquals(9.0, (float) $res->json('owner_retained.quantity'));
        $this->assertEquals(450.0, (float) $res->json('owner_retained.provisional_value'));
        $this->assertTrue($res->json('owner_retained.includes_implicit_owner'));
    }

    public function test_harvest_share_preview_supports_percent(): void
    {
        $this->createWipCost(12000.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 1000,
        ]);

        $worker = LabWorker::create([
            'tenant_id' => $this->tenant->id,
            'worker_no' => 'W-P3B',
            'name' => 'Labour',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'worker_id' => $worker->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 2.5,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");

        $res->assertStatus(200);
        $labour = collect($res->json('share_buckets'))->firstWhere('recipient_role', HarvestShareLine::RECIPIENT_LABOUR);
        $implicit = collect($res->json('share_buckets'))->firstWhere('implicit_owner', true);

        $this->assertEquals(25.0, (float) $labour['computed_qty']);
        $this->assertEquals(300.0, (float) $labour['provisional_value']);
        $this->assertEquals(12.0, (float) $labour['provisional_unit_cost']);
        $this->assertEquals(975.0, (float) $implicit['computed_qty']);
        $this->assertEquals(11700.0, (float) $implicit['provisional_value']);
        $this->assertEquals(12000.0, (float) $res->json('totals.sum_bucket_value'));
    }

    public function test_harvest_share_preview_supports_ratio(): void
    {
        $this->createWipCost(400.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-RAT',
            'name' => 'Tractor',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'machine_id' => $machine->id,
            'share_basis' => HarvestShareLine::BASIS_RATIO,
            'ratio_numerator' => 1,
            'ratio_denominator' => 3,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");

        $res->assertStatus(200);
        $m = collect($res->json('share_buckets'))->firstWhere('recipient_role', HarvestShareLine::RECIPIENT_MACHINE);
        $implicit = collect($res->json('share_buckets'))->firstWhere('implicit_owner', true);

        $this->assertEquals(25.0, (float) $m['computed_qty']);
        $this->assertEquals(75.0, (float) $implicit['computed_qty']);
        $this->assertEquals(100.0, (float) $m['provisional_value']);
        $this->assertEquals(300.0, (float) $implicit['provisional_value']);
        $this->assertEquals(400.0, (float) $res->json('totals.sum_bucket_value'));
    }

    public function test_harvest_share_preview_supports_remainder_bucket(): void
    {
        $this->createWipCost(1000.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-REM',
            'name' => 'Combine',
            'machine_type' => 'COMBINE',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'machine_id' => $machine->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 30,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'share_basis' => HarvestShareLine::BASIS_REMAINDER,
            'remainder_bucket' => true,
            'sort_order' => 2,
        ]);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");

        $res->assertStatus(200);
        $buckets = collect($res->json('share_buckets'));
        $mach = $buckets->firstWhere('recipient_role', HarvestShareLine::RECIPIENT_MACHINE);
        $ownerRem = $buckets->first(fn ($b) => $b['recipient_role'] === HarvestShareLine::RECIPIENT_OWNER && empty($b['implicit_owner']));

        $this->assertEquals(30.0, (float) $mach['computed_qty']);
        $this->assertEquals(70.0, (float) $ownerRem['computed_qty']);
        $this->assertEquals(300.0, (float) $mach['provisional_value']);
        $this->assertEquals(700.0, (float) $ownerRem['provisional_value']);
        $this->assertEquals(1000.0, (float) $res->json('totals.sum_bucket_value'));
    }

    public function test_harvest_share_preview_rejects_over_allocated_configuration(): void
    {
        $this->createWipCost(1000.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-OA',
            'name' => 'T1',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        foreach ([1 => 60.0, 2 => 60.0] as $sort => $pct) {
            HarvestShareLine::create([
                'tenant_id' => $this->tenant->id,
                'harvest_id' => $harvest->id,
                'harvest_line_id' => $hLine->id,
                'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'machine_id' => $machine->id,
                'share_basis' => HarvestShareLine::BASIS_PERCENT,
                'share_value' => $pct,
                'remainder_bucket' => false,
                'sort_order' => $sort,
            ]);
        }

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview")
            ->assertStatus(422);
    }

    public function test_harvest_share_preview_rejects_multiple_remainder_buckets(): void
    {
        $this->createWipCost(100.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 50,
        ]);

        // DB partial unique only applies when harvest_line_id IS NOT NULL; two harvest-level
        // remainder rows are blocked by API but preview must still reject if present (e.g. bad data).
        foreach ([1, 2] as $sort) {
            HarvestShareLine::create([
                'tenant_id' => $this->tenant->id,
                'harvest_id' => $harvest->id,
                'harvest_line_id' => null,
                'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
                'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
                'share_basis' => HarvestShareLine::BASIS_REMAINDER,
                'remainder_bucket' => true,
                'sort_order' => $sort,
            ]);
        }

        $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview")
            ->assertStatus(422);
    }

    public function test_existing_harvest_posting_still_works_without_share_lines(): void
    {
        $this->createWipCost(100.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $this->assertEquals(0, HarvestShareLine::where('harvest_id', $harvest->id)->count());

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();
        $this->assertEquals('POSTED', $harvest->status);
        $this->assertNotNull($harvest->posting_group_id);

        $postingGroup = PostingGroup::find($harvest->posting_group_id);
        $this->assertEquals('HARVEST', $postingGroup->source_type);

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(2, $ledgerEntries);

        $balance = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals(10, (float) $balance->qty_on_hand);
    }

    public function test_existing_harvest_reversal_still_works_without_share_lines(): void
    {
        $this->createWipCost(100.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $harvest->refresh();
        $this->assertEquals(0, HarvestShareLine::where('harvest_id', $harvest->id)->count());

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/reverse", [
                'reversal_date' => '2024-06-16',
                'reason' => 'Correction',
            ])
            ->assertStatus(200);

        $harvest->refresh();
        $this->assertEquals('REVERSED', $harvest->status);
        $this->assertNotNull($harvest->reversal_posting_group_id);

        $balanceAfter = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(0, (float) ($balanceAfter->qty_on_hand ?? 0));
    }

    public function test_post_with_in_kind_machine_splits_inventory_and_posts_in_kind_ledger_in_same_pg(): void
    {
        $this->createWipCost(500.0);

        $storeMachine = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Machine bin',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $hLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-INK',
            'name' => 'Harvester',
            'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        $share = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeMachine->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ])
            ->assertStatus(200);

        $harvest->refresh();
        $postingGroup = PostingGroup::findOrFail($harvest->posting_group_id);

        $allocations = AllocationRow::where('posting_group_id', $postingGroup->id)->orderBy('allocation_type')->get();
        $this->assertGreaterThanOrEqual(3, $allocations->count());
        $this->assertNotNull($allocations->firstWhere('allocation_type', 'HARVEST_IN_KIND_MACHINE'));

        $ledger = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $this->assertGreaterThanOrEqual(4, $ledger->count());

        $share->refresh();
        $this->assertEquals(1.0, (float) $share->computed_qty);
        $this->assertEquals(50.0, (float) $share->computed_value_snapshot);

        $this->assertEquals(1.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $storeMachine->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
        $this->assertEquals(9.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
    }
}
