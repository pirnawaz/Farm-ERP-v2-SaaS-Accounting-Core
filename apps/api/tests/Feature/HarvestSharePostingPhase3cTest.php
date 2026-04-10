<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\FieldJob;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
use App\Models\LabWorkerBalance;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineRateCard;
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
 * Phase 3C — Share-aware harvest posting & reversal: end-to-end correctness and legacy safety.
 *
 * @see docs/phase-3a-2-harvest-share-inventory-valuation.md
 * @see docs/phase-3a-3-harvest-share-accounting.md
 */
class HarvestSharePostingPhase3cTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CropCycle $cropCycle;

    private Project $project;

    private Party $hariParty;

    private InvItem $item;

    private InvStore $store;

    private Account $cropWipAccount;

    private Account $inventoryProduceAccount;

    private function enableModule(string $key): void
    {
        $this->enableModuleForTenant($this->tenant, $key);
    }

    private function enableModuleForTenant(Tenant $tenant, string $key): void
    {
        $m = Module::where('key', $key)->first();
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
     * Net CROP_WIP for harvest tests (same pattern as {@see HarvestTest::createWipCost}).
     */
    private function seedWip(float $amount, string $postingDate = '2024-05-01'): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => Str::uuid()->toString(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'wip-p3c-'.uniqid(),
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

    private function assertExactlyOneHarvestPostingGroupForHarvest(string $harvestId): PostingGroup
    {
        $rows = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'HARVEST')
            ->where('source_id', $harvestId)
            ->get();
        $this->assertCount(1, $rows, 'Exactly one HARVEST posting group per harvest');

        return $rows->first();
    }

    private function assertNoExtraOperationalPostingGroupsFromHarvest(string $harvestId): void
    {
        $this->assertSame(
            0,
            PostingGroup::where('tenant_id', $this->tenant->id)->where('source_type', 'SETTLEMENT')->where('source_id', $harvestId)->count()
        );
        $this->assertSame(
            0,
            PostingGroup::where('tenant_id', $this->tenant->id)->where('source_type', 'MACHINERY_CHARGE')->where('source_id', $harvestId)->count()
        );
    }

    private function accountId(string $code): string
    {
        return Account::where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Phase 3C Harvest', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModule('crop_ops');

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari Pool',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Project A',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grain',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $this->store = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $this->cropWipAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CROP_WIP')->first();
        $this->inventoryProduceAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'INVENTORY_PRODUCE')->first();
    }

    public function test_harvest_with_no_share_lines_posts_exactly_as_before(): void
    {
        $this->seedWip(100.0);

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
            'uom' => 'BAG',
        ]);

        $this->assertSame(0, HarvestShareLine::where('harvest_id', $harvest->id)->count());

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $harvest->refresh();
        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);
        $this->assertNoExtraOperationalPostingGroupsFromHarvest($harvest->id);

        $ledger = LedgerEntry::where('posting_group_id', $pg->id)->get();
        $this->assertCount(2, $ledger);

        $allocations = AllocationRow::where('posting_group_id', $pg->id)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame('HARVEST_PRODUCTION', $allocations->first()->allocation_type);

        $movements = InvStockMovement::where('posting_group_id', $pg->id)->where('movement_type', 'HARVEST')->get();
        $this->assertCount(1, $movements);

        $bal = InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('item_id', $this->item->id)->first();
        $this->assertEquals(10.0, (float) $bal->qty_on_hand);
        $this->assertEquals(100.0, (float) $bal->value_on_hand);
    }

    /** Design: 10 bags, $500 WIP, 1 bag machine in-kind (50 / 450 owner). */
    public function test_harvest_with_machine_in_kind_share_splits_inventory_and_posts_single_harvest_group(): void
    {
        $this->seedWip(500.0);

        $storeMachine = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Machine',
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

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M1',
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
            'harvest_line_id' => $line->id,
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
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);
        $this->assertNoExtraOperationalPostingGroupsFromHarvest($harvest->id);

        $caps = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_PRODUCTION')->get();
        $this->assertCount(2, $caps);

        $ink = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_IN_KIND_MACHINE')->get();
        $this->assertCount(1, $ink);
        $this->assertEquals('50.00', (string) $ink->first()->amount);

        $mv = InvStockMovement::where('posting_group_id', $pg->id)->where('movement_type', 'HARVEST')->orderBy('qty_delta')->get();
        $this->assertCount(2, $mv);

        $inc = $this->accountId('MACHINERY_SERVICE_INCOME');
        $exp = $this->accountId('EXP_SHARED');
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $inc)->where('debit_amount', '50.00')->first());
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $exp)->where('credit_amount', '50.00')->first());
    }

    /** Design: 1000 kg, $12,000 WIP, 2.5% labour = 25 kg / $300. */
    public function test_harvest_with_labour_in_kind_share_posts_settlement_pair_and_snaps_share_values(): void
    {
        $this->seedWip(12000.0);

        $storeLab = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Labour pool',
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

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 1000,
            'uom' => 'KG',
        ]);

        $worker = LabWorker::create([
            'tenant_id' => $this->tenant->id,
            'worker_no' => 'W-P3C',
            'name' => 'Worker',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
            'is_active' => true,
        ]);

        $share = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'worker_id' => $worker->id,
            'store_id' => $storeLab->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 2.5,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);

        $ink = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_IN_KIND_LABOUR')->first();
        $this->assertNotNull($ink);
        $this->assertEquals('300.00', (string) $ink->amount);

        $wp = $this->accountId('WAGES_PAYABLE');
        $le = $this->accountId('LABOUR_EXPENSE');
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $wp)->where('debit_amount', '300.00')->first());
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $le)->where('credit_amount', '300.00')->first());

        $share->refresh();
        $this->assertEquals(25.0, (float) $share->computed_qty);
        $this->assertEquals(300.0, (float) $share->computed_value_snapshot);
        $this->assertEqualsWithDelta(12.0, (float) $share->computed_unit_cost_snapshot, 0.0001);
    }

    public function test_harvest_with_landlord_in_kind_share_posts_expected_liability_expense_pair(): void
    {
        $this->seedWip(1000.0);

        $storeLl = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $landlordParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord Co',
            'party_types' => ['LANDLORD'],
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
            'uom' => 'BAG',
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LANDLORD,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'beneficiary_party_id' => $landlordParty->id,
            'store_id' => $storeLl->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 20,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);

        $this->assertNotNull(
            AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_IN_KIND_LANDLORD')->first()
        );

        $pay = $this->accountId('PAYABLE_LANDLORD');
        $ex = $this->accountId('EXP_LANDLORD_ONLY');
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $pay)->where('debit_amount', '200.00')->first());
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $ex)->where('credit_amount', '200.00')->first());
    }

    public function test_harvest_with_contractor_in_kind_share_posts_expected_accounting(): void
    {
        $this->seedWip(800.0);

        $storeC = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contractor',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $contractor = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Contractor Ltd',
            'party_types' => ['SUPPLIER'],
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 80,
            'uom' => 'BAG',
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_CONTRACTOR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'beneficiary_party_id' => $contractor->id,
            'store_id' => $storeC->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 8,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);

        $this->assertNotNull(
            AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_IN_KIND_CONTRACTOR')->first()
        );

        $ap = $this->accountId('AP');
        $exp = $this->accountId('EXP_SHARED');
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $ap)->where('debit_amount', '80.00')->first());
        $this->assertNotNull(LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $exp)->where('credit_amount', '80.00')->first());
    }

    /** Harvest-level (aggregate) share lines: all harvest_line_id null; one physical line total qty. */
    public function test_harvest_with_harvest_level_share_lines_posts_correctly(): void
    {
        $this->seedWip(500.0);

        $storeM = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Agg machine',
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

        $physLine = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-AGG',
            'name' => 'Tractor',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        $shareRow = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => null,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'inventory_item_id' => $this->item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 2,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        $this->assertNull($shareRow->fresh()->harvest_line_id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);

        $caps = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_PRODUCTION')->get();
        $this->assertCount(2, $caps);

        $implicit = $caps->first(fn ($a) => ($a->rule_snapshot['implicit_owner'] ?? false) === true);
        $this->assertNotNull($implicit);
        // Share rule is harvest-level (DB null), but cap snapshot still attributes stock to the physical line.
        $this->assertSame($physLine->id, $implicit->rule_snapshot['harvest_line_id'] ?? null);

        $this->assertEquals(8.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
        $this->assertEquals(2.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $storeM->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
    }

    public function test_harvest_with_line_scoped_share_lines_posts_correctly(): void
    {
        $this->seedWip(300.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 30,
            'uom' => 'BAG',
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 40,
            'remainder_bucket' => false,
            'sort_order' => 1,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_OWNER,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_CASH,
            'share_basis' => HarvestShareLine::BASIS_PERCENT,
            'share_value' => 60,
            'remainder_bucket' => false,
            'sort_order' => 2,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);

        $caps = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_PRODUCTION')->get();
        $this->assertCount(2, $caps);
        $this->assertEquals(300.0, round((float) $caps->sum('amount'), 2));
    }

    public function test_harvest_posting_snapshots_computed_qty_unit_cost_and_value_on_share_lines(): void
    {
        $this->seedWip(500.0);

        $storeM = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'M',
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

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MX',
            'name' => 'M',
            'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
            'is_active' => true,
        ]);

        $sl = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $sl->refresh();
        $this->assertNotNull($sl->computed_qty);
        $this->assertNotNull($sl->computed_unit_cost_snapshot);
        $this->assertNotNull($sl->computed_value_snapshot);
        $this->assertArrayHasKey('harvest_posting_group_id', $sl->rule_snapshot ?? []);
    }

    /**
     * Remainder design: 10% + 15% + 5% explicit; owner gets 70% residual; last bucket absorbs value cents.
     */
    public function test_harvest_posting_respects_rounding_and_remainder_policy(): void
    {
        $this->seedWip(12000.0);

        $sM = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'SM', 'type' => 'MAIN', 'is_active' => true]);
        $sL = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'SL', 'type' => 'MAIN', 'is_active' => true]);
        $sW = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'SW', 'type' => 'MAIN', 'is_active' => true]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 1000,
            'uom' => 'KG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MR', 'name' => 'M', 'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED', 'status' => 'ACTIVE', 'meter_unit' => 'HR', 'is_active' => true,
        ]);
        $landlord = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']]);
        $worker = LabWorker::create([
            'tenant_id' => $this->tenant->id, 'worker_no' => 'w', 'name' => 'W', 'worker_type' => 'HARI',
            'rate_basis' => 'DAILY', 'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id, 'harvest_id' => $harvest->id, 'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id, 'store_id' => $sM->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT, 'share_value' => 10,
            'sort_order' => 1,
        ]);
        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id, 'harvest_id' => $harvest->id, 'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LANDLORD,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'beneficiary_party_id' => $landlord->id, 'store_id' => $sL->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT, 'share_value' => 15,
            'sort_order' => 2,
        ]);
        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id, 'harvest_id' => $harvest->id, 'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'worker_id' => $worker->id, 'store_id' => $sW->id,
            'share_basis' => HarvestShareLine::BASIS_PERCENT, 'share_value' => 5,
            'sort_order' => 3,
        ]);

        $preview = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");
        $preview->assertStatus(200);
        $this->assertEquals(12000.0, (float) $preview->json('total_wip_cost'));

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $pg = $this->assertExactlyOneHarvestPostingGroupForHarvest($harvest->id);
        $prod = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'HARVEST_PRODUCTION')->get();
        $this->assertCount(4, $prod);

        $sumAmt = round((float) $prod->sum('amount'), 2);
        $this->assertEquals(12000.0, $sumAmt);

        $sumQty = round((float) $prod->sum(fn ($a) => (float) ($a->quantity ?? 0)), 3);
        $this->assertEquals(1000.0, $sumQty);
    }

    public function test_harvest_reverse_unwinds_share_inventory_and_gl(): void
    {
        $this->seedWip(500.0);

        $storeM = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'RevM', 'type' => 'MAIN', 'is_active' => true]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'REV', 'name' => 'M', 'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED', 'status' => 'ACTIVE', 'meter_unit' => 'HR', 'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'sort_order' => 1,
        ]);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $harvest->refresh();
        $origPgId = $harvest->posting_group_id;

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/reverse", [
                'reversal_date' => '2024-06-20',
                'reason' => 'Test reverse share harvest',
            ])
            ->assertStatus(200);

        $harvest->refresh();
        $this->assertEquals('REVERSED', $harvest->status);
        $revPg = PostingGroup::find($harvest->reversal_posting_group_id);
        $this->assertNotNull($revPg);

        $origLedger = LedgerEntry::where('posting_group_id', $origPgId)->get();
        $revLedger = LedgerEntry::where('posting_group_id', $revPg->id)->get();
        $this->assertSame($origLedger->count(), $revLedger->count());

        $origDebit = round((float) $origLedger->sum('debit_amount'), 2);
        $origCredit = round((float) $origLedger->sum('credit_amount'), 2);
        $revDebit = round((float) $revLedger->sum('debit_amount'), 2);
        $revCredit = round((float) $revLedger->sum('credit_amount'), 2);
        $this->assertEquals($origDebit, $revCredit);
        $this->assertEquals($origCredit, $revDebit);

        $this->assertEquals(0.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
        $this->assertEquals(0.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $storeM->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
    }

    public function test_harvest_repost_does_not_duplicate_share_effects(): void
    {
        $this->seedWip(100.0);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $storeM = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'IdemM', 'type' => 'MAIN', 'is_active' => true]);
        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'ID', 'name' => 'M', 'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED', 'status' => 'ACTIVE', 'meter_unit' => 'HR', 'is_active' => true,
        ]);

        HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'sort_order' => 1,
        ]);

        $r1 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $r1->assertStatus(200);
        $pg1 = $r1->json('posting_group_id');

        $r2 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $r2->assertStatus(200);
        $this->assertEquals($pg1, $r2->json('posting_group_id'));

        $this->assertCount(1, PostingGroup::where('tenant_id', $this->tenant->id)->where('source_type', 'HARVEST')->where('source_id', $harvest->id)->get());
    }

    public function test_preview_and_post_use_consistent_quantities_and_values(): void
    {
        $this->seedWip(500.0);

        $storeM = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'PV', 'type' => 'MAIN', 'is_active' => true]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PV', 'name' => 'M', 'machine_type' => 'HARVESTER',
            'ownership_type' => 'OWNED', 'status' => 'ACTIVE', 'meter_unit' => 'HR', 'is_active' => true,
        ]);

        $sl = HarvestShareLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $line->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeM->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 1,
            'sort_order' => 1,
        ]);

        $pv = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/share-preview?posting_date=2024-06-15");
        $pv->assertStatus(200);
        $machineBucket = collect($pv->json('share_buckets'))->firstWhere('share_line_id', $sl->id);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $sl->refresh();
        $this->assertEquals((float) $machineBucket['computed_qty'], (float) $sl->computed_qty);
        $this->assertEqualsWithDelta((float) $machineBucket['provisional_value'], (float) $sl->computed_value_snapshot, 0.01);
    }

    public function test_existing_harvest_reversal_still_works_without_share_lines(): void
    {
        $this->seedWip(100.0);

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

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $harvest->refresh();

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/reverse", [
                'reversal_date' => '2024-06-16',
                'reason' => 'Legacy reverse',
            ])
            ->assertStatus(200);

        $harvest->refresh();
        $this->assertEquals('REVERSED', $harvest->status);
        $this->assertEquals(0.0, (float) InvStockBalance::where('tenant_id', $this->tenant->id)->where('store_id', $this->store->id)->where('item_id', $this->item->id)->value('qty_on_hand'));
    }

    /** Regression: field job + machinery rate path still posts a single FIELD_JOB group (unchanged by harvest work). */
    public function test_existing_field_job_and_machinery_flows_still_work(): void
    {
        TenantContext::clear();

        $tenant = Tenant::create(['name' => 'FJ regression', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModuleForTenant($tenant, 'inventory');
        $this->enableModuleForTenant($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Input']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2024-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-P3C', 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-01', 'idempotency_key' => 'grn-p3c-1']);

        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'W1', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TR-P3C',
            'name' => 'Tractor',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
        ]);

        MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'machine_type' => null,
            'activity_type_id' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => true,
            'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $store->id,
            'item_id' => $item->id,
            'qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $worker->id,
            'units' => 1,
            'rate' => 100,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 3.5,
        ])->assertStatus(201);

        $post = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-p3c-1',
        ]);
        $post->assertStatus(201);

        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->firstOrFail();
        $this->assertGreaterThanOrEqual(1, AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->count());

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertEquals(8, (float) $bal->qty_on_hand);

        $wb = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertEquals(100, (float) $wb->payable_balance);

        $this->assertEquals('POSTED', FieldJob::find($jobId)->status);
    }

    public function test_harvest_economics_matches_posted_harvest_production_allocations(): void
    {
        $this->seedWip(500.0);

        $storeMachine = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Machine',
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

        $line = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
            'uom' => 'BAG',
        ]);

        $machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'M-ECO',
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
            'harvest_line_id' => $line->id,
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
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $res = $this->withHeaders($this->headers())
            ->getJson("/api/v1/crop-ops/harvests/{$harvest->id}/economics");
        $res->assertStatus(200);
        $j = $res->json();
        $this->assertEqualsWithDelta(10.0, (float) $j['total_output_qty'], 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $j['total_output_value'], 0.05);
        $this->assertEqualsWithDelta(9.0, (float) $j['retained_qty'], 0.001);
        $this->assertEqualsWithDelta(450.0, (float) $j['retained_value'], 0.05);
        $this->assertEqualsWithDelta(1.0, (float) $j['shared']['machine']['quantity'], 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $j['shared']['machine']['value'], 0.05);
        $this->assertEqualsWithDelta(0.0, (float) $j['shared']['labour']['value'], 0.01);
    }
}
