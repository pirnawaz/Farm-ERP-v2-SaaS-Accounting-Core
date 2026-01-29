<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Sale;
use App\Models\OperationalTransaction;
use App\Services\SaleService;
use App\Services\SaleCOGSService;
use App\Services\InventoryPostingService;
use App\Services\SettlementService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class HarvestIncomeSettlementLoopTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private InvStore $store;
    private InvItem $item;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $landlordParty;
    private Party $buyerParty;
    private Project $project;
    private ProjectRule $projectRule;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModule($this->tenant, 'inventory');
        $this->enableModule($this->tenant, 'settlements');

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Fertilizer']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Fertilizer Bag',
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
        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $this->hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $this->landlordParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $this->buyerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer',
            'party_types' => ['CUSTOMER'],
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
        $this->projectRule = ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 70.00,
            'profit_split_hari_pct' => 30.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create GRN to have stock (for inventory issue and COGS sale tests)
        $grn = InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $this->store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $this->item->id,
            'qty' => 100,
            'unit_cost' => 50.00,
            'line_total' => 5000.00,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $this->tenant->id, '2024-06-01', 'grn-1');
    }

    private function enableModule(Tenant $tenant, string $key): void
    {
        $m = Module::where('key', $key)->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_sale_income_appears_in_settlement(): void
    {
        $saleService = app(SaleService::class);
        $settlementService = app(SettlementService::class);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'amount' => 2000.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'due_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $saleService->postSale($sale->id, $this->tenant->id, '2024-06-15', 'sale-income-test', 'tenant_admin');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertEqualsWithDelta(2000.00, (float) $preview['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(2000.00, (float) $preview['pool_revenue'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $preview['total_expenses'], 0.01);
        $this->assertEqualsWithDelta(2000.00, (float) $preview['remaining_pool'], 0.01);
        // 70% landlord, 30% hari
        $this->assertEqualsWithDelta(1400.00, (float) $preview['landlord_gross'], 0.01);
        $this->assertEqualsWithDelta(600.00, (float) $preview['hari_gross'], 0.01);
    }

    public function test_sale_and_costs_produce_correct_net(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $saleService = app(SaleService::class);
        $settlementService = app(SettlementService::class);

        // Post shared inventory issue: 10 units * 50 = 500
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 70,
            'hari_share_pct' => 30,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 10,
        ]);
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-net');

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'amount' => 2000.00,
            'posting_date' => '2024-06-16',
            'sale_date' => '2024-06-16',
            'due_date' => '2024-06-16',
            'status' => 'DRAFT',
        ]);
        $saleService->postSale($sale->id, $this->tenant->id, '2024-06-16', 'sale-net-test', 'tenant_admin');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertEqualsWithDelta(2000.00, (float) $preview['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(500.00, (float) $preview['total_expenses'], 0.01);
        $remainingPool = (float) $preview['remaining_pool'];
        $this->assertEqualsWithDelta(2000.00 - 500.00, $remainingPool, 0.01);
        $this->assertEqualsWithDelta($remainingPool * 0.70, (float) $preview['landlord_gross'], 0.01);
        $this->assertEqualsWithDelta($remainingPool * 0.30, (float) $preview['hari_gross'], 0.01);
    }

    public function test_sale_reversal_unwinds_settlement_revenue(): void
    {
        $saleService = app(SaleService::class);
        $cogsService = app(SaleCOGSService::class);
        $settlementService = app(SettlementService::class);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'amount' => 2000.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'due_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        $saleService->postSale($sale->id, $this->tenant->id, '2024-06-15', 'sale-rev-test', 'tenant_admin');

        $previewBefore = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');
        $this->assertEqualsWithDelta(2000.00, (float) $previewBefore['total_revenue'], 0.01);

        $sale->refresh();
        $cogsService->reverseSale($sale, '2024-06-20', 'Reversal test');

        $previewAfter = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');
        $this->assertEqualsWithDelta(0, (float) $previewAfter['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $previewAfter['pool_revenue'], 0.01);

        $incomeOt = OperationalTransaction::where('posting_group_id', $sale->posting_group_id)
            ->where('type', 'INCOME')
            ->first();
        $this->assertNotNull($incomeOt);
        $this->assertEquals('VOID', $incomeOt->status);
    }

    public function test_sale_without_project_id_cannot_be_posted(): void
    {
        $saleService = app(SaleService::class);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => null,
            'crop_cycle_id' => null,
            'amount' => 2000.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'due_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Sale must have a project_id to be posted for settlement.');

        $saleService->postSale($sale->id, $this->tenant->id, '2024-06-15', 'sale-no-project', 'tenant_admin');
    }

}
