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
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\LabWorkerBalance;
use App\Services\InventoryPostingService;
use App\Services\LabourPostingService;
use App\Services\SettlementService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ProjectSettlementCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private InvStore $store;
    private InvItem $item;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $landlordParty;
    private Project $project;
    private ProjectRule $projectRule;
    private LabWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModule($this->tenant, 'inventory');
        $this->enableModule($this->tenant, 'labour');
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
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
        $this->projectRule = ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 60.00,
            'profit_split_hari_pct' => 40.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $this->worker = LabWorker::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-001',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
        ]);
        LabWorkerBalance::getOrCreate($this->tenant->id, $this->worker->id);

        // Create GRN to have stock
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

    public function test_expenses_appear_with_zero_income(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $labPosting = app(LabourPostingService::class);
        $settlementService = app(SettlementService::class);

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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-exp-zero');

        $workLog = LabWorkLog::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'WL-1',
            'worker_id' => $this->worker->id,
            'work_date' => '2024-06-16',
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 75,
            'amount' => 75,
            'status' => 'DRAFT',
        ]);
        $labPosting->postWorkLog($workLog->id, $this->tenant->id, '2024-06-16', 'lab-exp-zero');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertEquals(0, (float) $preview['total_revenue']);
        $this->assertGreaterThan(0, (float) $preview['total_expenses']);
        $this->assertGreaterThan(0, (float) $preview['shared_costs']);
        $this->assertArrayHasKey('total_revenue', $preview);
        $this->assertArrayHasKey('total_expenses', $preview);
        $this->assertArrayHasKey('shared_costs', $preview);
        $this->assertArrayHasKey('landlord_only_costs', $preview);
        $this->assertArrayHasKey('hari_only_deductions', $preview);
    }

    public function test_classification_mapping_landlord_only_and_hari_only(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

        $farmerOnlyIssue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-FARMER',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'FARMER_ONLY',
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $farmerOnlyIssue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);
        $invPosting->postIssue($farmerOnlyIssue->id, $this->tenant->id, '2024-06-15', 'issue-farmer');

        $hariOnlyIssue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-HARI',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-16',
            'status' => 'DRAFT',
            'allocation_mode' => 'HARI_ONLY',
            'hari_id' => $this->hariParty->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $hariOnlyIssue->id,
            'item_id' => $this->item->id,
            'qty' => 1,
        ]);
        $invPosting->postIssue($hariOnlyIssue->id, $this->tenant->id, '2024-06-16', 'issue-hari');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertEqualsWithDelta(100.00, (float) $preview['landlord_only_costs'], 0.01);
        $this->assertEqualsWithDelta(50.00, (float) $preview['hari_only_deductions'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $preview['shared_costs'], 0.01);
    }

    public function test_reversal_unwinds_settlement(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-REV',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 50,
            'hari_share_pct' => 50,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-rev');

        $previewBefore = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');
        $this->assertEqualsWithDelta(100.00, (float) $previewBefore['shared_costs'], 0.01);
        $this->assertEqualsWithDelta(100.00, (float) $previewBefore['total_expenses'], 0.01);

        $invPosting->reverseIssue($issue->id, $this->tenant->id, '2024-06-20', 'Reversal test');

        $previewAfter = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');
        $this->assertEqualsWithDelta(0, (float) $previewAfter['shared_costs'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $previewAfter['total_expenses'], 0.01);
    }

    public function test_100_0_share_rule_assigns_pool_net_entirely_to_landlord(): void
    {
        $this->projectRule->update([
            'profit_split_landlord_pct' => 100.00,
            'profit_split_hari_pct' => 0.00,
        ]);

        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-100',
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-100');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $remainingPool = (float) $preview['remaining_pool'];
        $this->assertEqualsWithDelta($remainingPool, (float) $preview['landlord_gross'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $preview['hari_net'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $preview['hari_gross'], 0.01);
    }
}
