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
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\OperationalTransaction;
use App\Services\InventoryPostingService;
use App\Services\LabourPostingService;
use App\Services\SaleCOGSService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ReconciliationReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private InvStore $store;
    private InvItem $item;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $buyerParty;
    private Party $vendorParty;
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
        $this->enableModule($this->tenant, 'ar_sales');
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
        $this->buyerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $this->vendorParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Vendor',
            'party_types' => ['VENDOR'],
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
            'qty' => 1000,
            'unit_cost' => 1.00,
            'line_total' => 1000.00,
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

    private function seedPostedSaleAndExpense(): void
    {
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 2000.00,
            'posting_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 100,
            'unit_price' => 20.00,
            'line_total' => 2000.00,
        ]);
        $sale->refresh();
        app(SaleCOGSService::class)->postSaleWithCOGS($sale, '2024-06-15', 'recon-sale-1');

        $this->seedPostedExpensesOnly();
    }

    /** Seed only expense postings (issue + work log) so ledger and OT match without COGS. */
    private function seedPostedExpensesOnly(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-16',
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
        app(InventoryPostingService::class)->postIssue($issue->id, $this->tenant->id, '2024-06-16', 'recon-issue-1');

        $workLog = LabWorkLog::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'WL-1',
            'worker_id' => $this->worker->id,
            'work_date' => '2024-06-17',
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'rate_basis' => 'DAILY',
            'units' => 3,
            'rate' => 100,
            'amount' => 300,
            'status' => 'DRAFT',
        ]);
        app(LabourPostingService::class)->postWorkLog($workLog->id, $this->tenant->id, '2024-06-17', 'recon-lab-1');
    }

    public function test_reconciliation_project_validation_requires_from_to_project_id(): void
    {
        $url = '/api/reports/reconciliation/project';
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson($url)
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson($url . '?project_id=' . $this->project->id)
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson($url . '?project_id=' . $this->project->id . '&from=2024-01-01')
            ->assertStatus(422);
    }

    public function test_reconciliation_project_response_shape_and_pass_when_in_sync(): void
    {
        // Use expenses-only seed so ledger and OT match (sale+COGS would add ledger expense not in OT).
        $this->seedPostedExpensesOnly();

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/reconciliation/project?project_id=' . $this->project->id . '&from=2024-01-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertIsArray($data['checks']);
        $this->assertNotEmpty($data['checks']);

        $hasPass = false;
        foreach ($data['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('title', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('summary', $check);
            $this->assertArrayHasKey('details', $check);
            $this->assertContains($check['status'], ['PASS', 'WARN', 'FAIL']);
            if ($check['status'] === 'PASS') {
                $hasPass = true;
            }
        }
        $this->assertTrue($hasPass, 'At least one check should be PASS when data is in sync');
    }

    public function test_reconciliation_project_fail_when_ot_mismatch(): void
    {
        $this->seedPostedSaleAndExpense();

        // Corrupt one OT amount so ledger vs OT check fails
        $ot = OperationalTransaction::where('tenant_id', $this->tenant->id)
            ->where('project_id', $this->project->id)
            ->where('type', 'INCOME')
            ->first();
        $this->assertNotNull($ot);
        $ot->update(['amount' => $ot->amount + 100]);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/reconciliation/project?project_id=' . $this->project->id . '&from=2024-01-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $hasFail = false;
        foreach ($data['checks'] as $check) {
            if ($check['status'] === 'FAIL') {
                $hasFail = true;
                $this->assertNotEmpty($check['summary']);
                break;
            }
        }
        $this->assertTrue($hasFail, 'At least one check should be FAIL when OT and ledger diverge');
    }

    public function test_reconciliation_crop_cycle_validation_and_response_shape(): void
    {
        $this->seedPostedSaleAndExpense();

        $url = '/api/reports/reconciliation/crop-cycle?crop_cycle_id=' . $this->cropCycle->id . '&from=2024-01-01&to=2024-06-30';
        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson($url);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertIsArray($data['checks']);
        foreach ($data['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('title', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('summary', $check);
            $this->assertArrayHasKey('details', $check);
        }

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/reconciliation/crop-cycle?from=2024-01-01&to=2024-06-30')
            ->assertStatus(422);
    }

    public function test_reconciliation_supplier_ap_response_shape(): void
    {
        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/reconciliation/supplier-ap?party_id=' . $this->vendorParty->id . '&from=2024-01-01&to=2024-12-31');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertIsArray($data['checks']);
        foreach ($data['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('title', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('summary', $check);
            $this->assertArrayHasKey('details', $check);
        }
    }

    public function test_reconciliation_supplier_ap_validation_requires_party_id(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/reconciliation/supplier-ap?from=2024-01-01&to=2024-12-31')
            ->assertStatus(422);
    }
}
