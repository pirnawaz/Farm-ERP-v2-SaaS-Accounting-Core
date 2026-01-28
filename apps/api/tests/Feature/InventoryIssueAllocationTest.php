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
use App\Models\ShareRule;
use App\Models\ShareRuleLine;
use App\Models\InvStockBalance;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\InventoryPostingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InventoryIssueAllocationTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableInventory($this->tenant);

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

        $postingService = app(InventoryPostingService::class);
        $postingService->postGRN($grn->id, $this->tenant->id, '2024-06-01', 'grn-1');
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

    public function test_purchase_creates_inventory_asset_only(): void
    {
        // GRN posting should create INVENTORY_INPUTS asset and AP/CASH liability
        // No project allocation should be created
        $postingGroups = PostingGroup::where('source_type', 'INVENTORY_GRN')->get();
        $this->assertCount(1, $postingGroups);

        $allocationRows = AllocationRow::whereIn('posting_group_id', $postingGroups->pluck('id'))->get();
        $this->assertCount(0, $allocationRows, 'GRN should not create allocation rows');
    }

    public function test_issue_with_hari_only_creates_allocation_and_settlement(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'HARI_ONLY',
            'hari_id' => $this->hariParty->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-1');

        // Verify AllocationRow created
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(1, $allocationRows);
        $allocationRow = $allocationRows->first();
        $this->assertEquals('HARI_ONLY', $allocationRow->allocation_type);
        $this->assertEquals($this->hariParty->id, $allocationRow->party_id);
        $this->assertEquals('100.00', $allocationRow->amount); // 2 * 50

        // Verify ledger entries are balanced
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $totalDebits = $ledgerEntries->sum('debit_amount');
        $totalCredits = $ledgerEntries->sum('credit_amount');
        $this->assertEquals($totalDebits, $totalCredits);

        // Verify settlement balance entries exist (PAYABLE_HARI and clearing)
        // NOTE: HARI_ONLY mode correctly uses PAYABLE_HARI because Hari pays 100% of the expense
        $payableHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PAYABLE_HARI';
        });
        $this->assertGreaterThan(0, $payableHariEntries->count(), 'HARI_ONLY should create PAYABLE_HARI (Hari pays 100%)');
    }

    public function test_issue_with_farmer_only_creates_landlord_allocation(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-2',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'FARMER_ONLY',
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-2');

        // Verify AllocationRow created for landlord
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(1, $allocationRows);
        $allocationRow = $allocationRows->first();
        $this->assertEquals('POOL_SHARE', $allocationRow->allocation_type);
        $this->assertEquals($this->landlordParty->id, $allocationRow->party_id);
    }

    public function test_issue_with_shared_creates_split_allocations(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-3',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60.00,
            'hari_share_pct' => 40.00,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-3');

        // Verify two AllocationRows created (landlord and hari)
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(2, $allocationRows);

        $landlordRow = $allocationRows->firstWhere('party_id', $this->landlordParty->id);
        $hariRow = $allocationRows->firstWhere('party_id', $this->hariParty->id);

        $this->assertNotNull($landlordRow);
        $this->assertNotNull($hariRow);
        $this->assertEquals('60.00', $landlordRow->amount); // 100 * 60%
        $this->assertEquals('40.00', $hariRow->amount); // 100 * 40%

        // Verify ledger entries are balanced
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $totalDebits = $ledgerEntries->sum('debit_amount');
        $totalCredits = $ledgerEntries->sum('credit_amount');
        $this->assertEquals($totalDebits, $totalCredits);

        // Verify correct accounting for shared expenses:
        // 1. DUE_FROM_HARI entry exists with hari_share amount
        $dueFromHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'DUE_FROM_HARI';
        });
        $this->assertCount(1, $dueFromHariEntries, 'DUE_FROM_HARI entry should exist');
        $dueFromHariEntry = $dueFromHariEntries->first();
        $this->assertEquals('40.00', $dueFromHariEntry->debit_amount);
        $this->assertEquals('0', $dueFromHariEntry->credit_amount);

        // 2. INPUTS_EXPENSE has credit entry for hari_share (reducing expense)
        $inputsExpenseEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'INPUTS_EXPENSE';
        });
        $this->assertCount(2, $inputsExpenseEntries, 'INPUTS_EXPENSE should have debit (full) and credit (hari share) entries');
        $inputsExpenseDebit = $inputsExpenseEntries->sum('debit_amount');
        $inputsExpenseCredit = $inputsExpenseEntries->sum('credit_amount');
        $this->assertEquals('100.00', $inputsExpenseDebit, 'Full expense should be debited');
        $this->assertEquals('40.00', $inputsExpenseCredit, 'Hari share should be credited (reducing expense)');
        $netExpense = (float) $inputsExpenseDebit - (float) $inputsExpenseCredit;
        $this->assertEquals(60.00, $netExpense, 'Net expense should equal landlord share');

        // 3. No PROFIT_DISTRIBUTION entries
        $profitDistributionEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PROFIT_DISTRIBUTION';
        });
        $this->assertCount(0, $profitDistributionEntries, 'PROFIT_DISTRIBUTION should not be used for expenses');

        // 4. No PAYABLE_HARI entries for SHARED mode
        $payableHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PAYABLE_HARI';
        });
        $this->assertCount(0, $payableHariEntries, 'PAYABLE_HARI should not be used for shared expenses');
    }

    public function test_issue_with_shared_and_explicit_percentages(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-4',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 70.00,
            'hari_share_pct' => 30.00,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-4');

        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();
        $landlordRow = $allocationRows->firstWhere('party_id', $this->landlordParty->id);
        $hariRow = $allocationRows->firstWhere('party_id', $this->hariParty->id);

        $this->assertEquals('70.00', $landlordRow->amount); // 100 * 70%
        $this->assertEquals('30.00', $hariRow->amount); // 100 * 30%
    }

    public function test_shared_inventory_expense_creates_receivable_not_payable(): void
    {
        // This test specifically verifies the accounting fix: shared expenses create DUE_FROM_HARI, not PAYABLE_HARI
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-SHARED-TEST',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 70.00,
            'hari_share_pct' => 30.00,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2, // Total value = 100.00
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'shared-test');

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->with('account')
            ->get();

        // Verify DUE_FROM_HARI is created (receivable)
        $dueFromHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'DUE_FROM_HARI';
        });
        $this->assertCount(1, $dueFromHariEntries, 'DUE_FROM_HARI should be created for shared expenses');
        $this->assertEquals('30.00', $dueFromHariEntries->first()->debit_amount, 'DUE_FROM_HARI should equal hari share');

        // Verify INPUTS_EXPENSE is reduced
        $inputsExpenseEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'INPUTS_EXPENSE';
        });
        $inputsExpenseDebit = $inputsExpenseEntries->sum('debit_amount');
        $inputsExpenseCredit = $inputsExpenseEntries->sum('credit_amount');
        $netExpense = (float) $inputsExpenseDebit - (float) $inputsExpenseCredit;
        $this->assertEquals(70.00, $netExpense, 'Net expense should equal landlord share (70%)');

        // Verify no PAYABLE_HARI
        $payableHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PAYABLE_HARI';
        });
        $this->assertCount(0, $payableHariEntries, 'PAYABLE_HARI should NOT be created for shared expenses');

        // Verify no PROFIT_DISTRIBUTION
        $profitDistributionEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PROFIT_DISTRIBUTION';
        });
        $this->assertCount(0, $profitDistributionEntries, 'PROFIT_DISTRIBUTION should NOT be used for expenses');
    }

    public function test_hari_only_inventory_expense_still_creates_payable(): void
    {
        // Verify HARI_ONLY mode still creates PAYABLE_HARI (unchanged behavior - this is correct)
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-HARI-ONLY-TEST',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'HARI_ONLY',
            'hari_id' => $this->hariParty->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2, // Total value = 100.00
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'hari-only-test');

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->with('account')
            ->get();

        // Verify PAYABLE_HARI is created (correct for HARI_ONLY)
        $payableHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'PAYABLE_HARI';
        });
        $this->assertCount(1, $payableHariEntries, 'HARI_ONLY should create PAYABLE_HARI');
        $this->assertEquals('100.00', $payableHariEntries->first()->debit_amount);

        // Verify no DUE_FROM_HARI (not used for HARI_ONLY)
        $dueFromHariEntries = $ledgerEntries->filter(function ($e) {
            return $e->account->code === 'DUE_FROM_HARI';
        });
        $this->assertCount(0, $dueFromHariEntries, 'DUE_FROM_HARI should NOT be used for HARI_ONLY');
    }

    public function test_issue_with_shared_and_share_rule(): void
    {
        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Share Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'is_active' => true,
            'version' => 1,
        ]);
        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 55.00,
            'role' => 'LANDLORD',
        ]);
        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->hariParty->id,
            'percentage' => 45.00,
            'role' => 'HARI',
        ]);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-5',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'sharing_rule_id' => $shareRule->id,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-5');

        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();
        $landlordRow = $allocationRows->firstWhere('party_id', $this->landlordParty->id);
        $hariRow = $allocationRows->firstWhere('party_id', $this->hariParty->id);

        $this->assertEquals('55.00', $landlordRow->amount); // 100 * 55%
        $this->assertEquals('45.00', $hariRow->amount); // 100 * 45%
    }

    public function test_posting_is_idempotent(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-6',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60.00,
            'hari_share_pct' => 40.00,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $idempotencyKey = 'issue-6';
        $postingGroup1 = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', $idempotencyKey);
        $postingGroup2 = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', $idempotencyKey);

        $this->assertEquals($postingGroup1->id, $postingGroup2->id);

        // Verify no duplicate allocation rows
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup1->id)->get();
        $this->assertCount(2, $allocationRows);
    }

    public function test_cannot_post_without_allocation_mode(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)->withHeader('X-User-Role', 'accountant');
        $r = $this->postJson('/api/v1/inventory/issues', [
            'doc_no' => 'ISS-7',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'lines' => [['item_id' => $this->item->id, 'qty' => 2]],
        ]);
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['allocation_mode']);
    }

    public function test_cannot_post_hari_only_without_hari_id(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)->withHeader('X-User-Role', 'accountant');
        $r = $this->postJson('/api/v1/inventory/issues', [
            'doc_no' => 'ISS-8',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'lines' => [['item_id' => $this->item->id, 'qty' => 2]],
            'allocation_mode' => 'HARI_ONLY',
        ]);
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['hari_id']);
    }

    public function test_party_statement_includes_inventory_issue_allocations(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-9',
            'store_id' => $this->store->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'allocation_mode' => 'SHARED',
            'landlord_share_pct' => 60.00,
            'hari_share_pct' => 40.00,
        ]);
        InvIssueLine::create([
            'tenant_id' => $this->tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $this->item->id,
            'qty' => 2,
        ]);

        $postingService = app(InventoryPostingService::class);
        $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-9');

        // Get party statement for hari party
        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$this->hariParty->id}/statement?from=2024-01-01&to=2024-12-31");

        $response->assertStatus(200);
        $data = $response->json();
        
        // Check that inventory issue allocations are included
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('total_inventory_issue_allocations', $data['summary']);
        $this->assertEquals('40.00', $data['summary']['total_inventory_issue_allocations']);
    }
}
