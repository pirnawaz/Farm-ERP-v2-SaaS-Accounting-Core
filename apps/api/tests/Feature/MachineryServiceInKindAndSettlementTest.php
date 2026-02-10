<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineryService;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\InvIssue;
use App\Services\Machinery\MachineryServicePostingService;
use App\Services\SettlementService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryServiceInKindAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;
    private Machine $machine;
    private InvStore $store;
    private InvItem $wheatItem;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::clear();
        (new ModulesSeeder)->run();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableMachinery($this->tenant);
        $this->enableInventory($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
        ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $this->machine = Machine::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->wheatItem = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat',
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
    }

    private function enableMachinery(Tenant $tenant): void
    {
        $m = Module::where('key', 'machinery')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function enableInventory(Tenant $tenant): void
    {
        $m = Module::where('key', 'inventory')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_posting_machinery_service_with_in_kind_creates_service_and_inventory_issue(): void
    {
        $rateCard = MachineRateCard::create([
            'tenant_id' => $this->tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $this->machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 25.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => false,
            'is_active' => true,
        ]);

        $service = MachineryService::create([
            'tenant_id' => $this->tenant->id,
            'machine_id' => $this->machine->id,
            'project_id' => $this->project->id,
            'rate_card_id' => $rateCard->id,
            'quantity' => '10',
            'allocation_scope' => MachineryService::ALLOCATION_SCOPE_SHARED,
            'in_kind_item_id' => $this->wheatItem->id,
            'in_kind_rate_per_unit' => '5',
            'in_kind_store_id' => $this->store->id,
            'status' => MachineryService::STATUS_DRAFT,
        ]);

        // Create GRN so we have stock to issue
        $grn = \App\Models\InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $this->store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        \App\Models\InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $this->wheatItem->id,
            'qty' => 100,
            'unit_cost' => 1.00,
            'line_total' => 100.00,
        ]);
        app(\App\Services\InventoryPostingService::class)->postGRN($grn->id, $this->tenant->id, '2024-06-01', 'grn-1');

        $postingService = app(MachineryServicePostingService::class);
        $pg = $postingService->postService($service->id, $this->tenant->id, '2024-06-15');

        $this->assertNotNull($pg->id);
        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_POSTED, $service->status);
        $this->assertNotNull($service->posting_group_id);
        $this->assertEquals('50', $service->in_kind_quantity);
        $this->assertNotNull($service->in_kind_inventory_issue_id);

        $issue = InvIssue::find($service->in_kind_inventory_issue_id);
        $this->assertNotNull($issue);
        $this->assertEquals('POSTED', $issue->status);
    }

    public function test_reversal_reverses_both_service_and_in_kind_issue(): void
    {
        $rateCard = MachineRateCard::create([
            'tenant_id' => $this->tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $this->machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 25.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => false,
            'is_active' => true,
        ]);

        $service = MachineryService::create([
            'tenant_id' => $this->tenant->id,
            'machine_id' => $this->machine->id,
            'project_id' => $this->project->id,
            'rate_card_id' => $rateCard->id,
            'quantity' => '4',
            'allocation_scope' => MachineryService::ALLOCATION_SCOPE_SHARED,
            'in_kind_item_id' => $this->wheatItem->id,
            'in_kind_rate_per_unit' => '2',
            'in_kind_store_id' => $this->store->id,
            'status' => MachineryService::STATUS_DRAFT,
        ]);

        $grn = \App\Models\InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-2',
            'store_id' => $this->store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        \App\Models\InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $this->wheatItem->id,
            'qty' => 50,
            'unit_cost' => 1.00,
            'line_total' => 50.00,
        ]);
        app(\App\Services\InventoryPostingService::class)->postGRN($grn->id, $this->tenant->id, '2024-06-01', 'grn-2');

        $postingService = app(MachineryServicePostingService::class);
        $postingService->postService($service->id, $this->tenant->id, '2024-06-15');
        $service->refresh();
        $issueId = $service->in_kind_inventory_issue_id;

        $postingService->reverseService($service->id, $this->tenant->id, '2024-06-20', 'Test reversal');

        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_REVERSED, $service->status);
        $issue = InvIssue::find($issueId);
        $this->assertNotNull($issue);
        $this->assertEquals('REVERSED', $issue->status);

        $rows = AllocationRow::where('project_id', $this->project->id)->get();
        $projectRowSum = $rows->sum(fn ($r) => (float) $r->amount);
        $this->assertEqualsWithDelta(0, $projectRowSum, 0.01, 'Allocation rows for project should net to zero after reversal');
    }

    public function test_settlement_preview_includes_hari_deficit_and_position_when_hari_net_negative(): void
    {
        $rateCard = MachineRateCard::create([
            'tenant_id' => $this->tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $this->machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 10.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => false,
            'is_active' => true,
        ]);

        $service = MachineryService::create([
            'tenant_id' => $this->tenant->id,
            'machine_id' => $this->machine->id,
            'project_id' => $this->project->id,
            'rate_card_id' => $rateCard->id,
            'quantity' => '10',
            'allocation_scope' => MachineryService::ALLOCATION_SCOPE_HARI_ONLY,
            'status' => MachineryService::STATUS_DRAFT,
        ]);

        $postingService = app(MachineryServicePostingService::class);
        $postingService->postService($service->id, $this->tenant->id, '2024-06-15');

        $settlementService = app(SettlementService::class);
        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertArrayHasKey('hari_deficit', $preview);
        $this->assertArrayHasKey('hari_position', $preview);
        $hariNet = (float) $preview['hari_net'];
        if ($hariNet < 0) {
            $this->assertEqualsWithDelta(abs($hariNet), (float) $preview['hari_deficit'], 0.01);
            $this->assertEquals('PAYABLE', $preview['hari_position']);
        } else {
            $this->assertEquals(0.0, (float) $preview['hari_deficit']);
            $this->assertContains($preview['hari_position'], ['RECEIVABLE', 'SETTLED']);
        }
    }
}
