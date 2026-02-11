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
use App\Models\AllocationRow;
use App\Models\OperationalTransaction;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Services\InventoryPostingService;
use App\Services\PostingService;
use App\Services\TenantContext;
use App\Http\Controllers\ReportController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ReportAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private InvStore $store;
    private InvItem $item;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $landlordParty;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModule($this->tenant, 'inventory');
        $this->enableModule($this->tenant, 'reports');

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
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
        ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 60.00,
            'profit_split_hari_pct' => 40.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

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

    public function test_project_pl_does_not_double_count_with_multiple_allocation_rows(): void
    {
        $invPosting = app(InventoryPostingService::class);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60,
            'hari_share_pct' => 40,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-1');

        $request = Request::create('/api/reports/project-pl', 'GET', [
            'from' => '2024-06-01',
            'to' => '2024-06-30',
            'project_id' => $this->project->id,
        ]);
        $request->attributes->set('tenant_id', $this->tenant->id);

        $controller = app(ReportController::class);
        $response = $controller->projectPL($request);
        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertEquals($this->project->id, $row['project_id']);
        $expenses = (float) $row['expenses'];
        $income = (float) $row['income'];
        $netProfit = (float) $row['net_profit'];
        $this->assertEqualsWithDelta(100.00, $expenses, 0.01);
        $this->assertEqualsWithDelta(0, $income, 0.01);
        $this->assertEqualsWithDelta(-100.00, $netProfit, 0.01);
    }

    public function test_pl_includes_only_income_and_expense_accounts(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-2',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60,
            'hari_share_pct' => 40,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-2');

        $request = Request::create('/api/reports/project-pl', 'GET', [
            'from' => '2024-06-01',
            'to' => '2024-06-30',
        ]);
        $request->attributes->set('tenant_id', $this->tenant->id);
        $controller = app(ReportController::class);
        $response = $controller->projectPL($request);
        $data = json_decode($response->getContent(), true);

        $projectRow = collect($data)->firstWhere('project_id', $this->project->id);
        $this->assertNotNull($projectRow);
        $this->assertEqualsWithDelta(100.00, (float) $projectRow['expenses'], 0.01);
    }

    /**
     * FARM_OVERHEAD postings must have allocation_row.project_id = NULL and must NOT appear in project P&L.
     */
    public function test_farm_overhead_not_in_project_pl_and_allocation_row_project_id_null(): void
    {
        $postingService = app(PostingService::class);

        $sharedTxn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-10',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);
        $postingService->postOperationalTransaction($sharedTxn->id, $this->tenant->id, '2024-06-10', 'idem-shared');

        $overheadTxn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => null,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-12',
            'amount' => 50.00,
            'classification' => 'FARM_OVERHEAD',
        ]);
        $postingService->postOperationalTransaction($overheadTxn->id, $this->tenant->id, '2024-06-12', 'idem-overhead');

        $overheadRow = AllocationRow::where('posting_group_id', $overheadTxn->fresh()->posting_group_id)->first();
        $this->assertNotNull($overheadRow);
        $this->assertNull($overheadRow->project_id, 'FARM_OVERHEAD allocation row must have project_id NULL');

        $request = Request::create('/api/reports/project-pl', 'GET', [
            'from' => '2024-06-01',
            'to' => '2024-06-30',
            'project_id' => $this->project->id,
        ]);
        $request->attributes->set('tenant_id', $this->tenant->id);
        $controller = app(ReportController::class);
        $response = $controller->projectPL($request);
        $data = json_decode($response->getContent(), true);

        $projectRow = collect($data)->firstWhere('project_id', $this->project->id);
        $this->assertNotNull($projectRow);
        $this->assertEqualsWithDelta(100.00, (float) $projectRow['expenses'], 0.01, 'Project P&L must exclude FARM_OVERHEAD (50) and show only SHARED (100)');
    }
}
