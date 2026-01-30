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
use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\InventoryPostingService;
use App\Services\SettlementService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ProjectSettlementLedgerTruthTest extends TestCase
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

    public function test_settlement_computes_profit_from_ledger_entries(): void
    {
        $invPosting = app(InventoryPostingService::class);
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-1');

        $preview = $settlementService->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');
        $ledgerProfit = $settlementService->getProjectProfitFromLedger($this->project->id, $this->tenant->id, '2024-06-30');

        $this->assertEqualsWithDelta($ledgerProfit['total_revenue'], (float) $preview['total_revenue'], 0.01);
        $this->assertEqualsWithDelta($ledgerProfit['total_expenses'], (float) $preview['total_expenses'], 0.01);
        $this->assertEqualsWithDelta($ledgerProfit['pool_profit'], (float) $preview['pool_profit'], 0.01);
    }

    public function test_settlement_posting_uses_profit_distribution_clearing_and_party_control_only(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

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

        // Add revenue so there is profit to distribute (expense 100, revenue 200 → profit 100)
        $arAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'AR')->first();
        $revenueAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $pgRev = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $this->project->id,
            'posting_date' => '2024-06-14',
            'idempotency_key' => 'test-rev-1',
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'project_id' => $this->project->id,
            'party_id' => $this->landlordParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 200,
            'rule_snapshot' => [],
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'account_id' => $arAccount->id,
            'debit_amount' => 200,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'account_id' => $revenueAccount->id,
            'debit_amount' => 0,
            'credit_amount' => 200,
            'currency_code' => 'GBP',
        ]);

        $result = $settlementService->postSettlement(
            $this->project->id,
            $this->tenant->id,
            '2024-06-30',
            'settlement-test-key-1',
            '2024-06-30',
            false,
            null
        );

        $postingGroup = $result['posting_group'];
        $this->assertEquals('SETTLEMENT', $postingGroup->source_type);

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->with('account')->get();
        $codes = $ledgerEntries->pluck('account.code')->unique()->values()->all();

        $this->assertContains('PROFIT_DISTRIBUTION_CLEARING', $codes);
        $this->assertContains('PARTY_CONTROL_LANDLORD', $codes);
        $this->assertContains('PARTY_CONTROL_HARI', $codes);
        $this->assertNotContains('PROFIT_DISTRIBUTION', $codes);
        $this->assertNotContains('PAYABLE_LANDLORD', $codes);
        $this->assertNotContains('PAYABLE_HARI', $codes);
    }

    public function test_settlement_idempotency_same_key_returns_same_result(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-3',
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-3');

        // Add revenue so there is profit (expense 100, revenue 150 → profit 50)
        $arAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'AR')->first();
        $revenueAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $pgRev = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $this->project->id,
            'posting_date' => '2024-06-14',
            'idempotency_key' => 'test-rev-idem',
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'project_id' => $this->project->id,
            'party_id' => $this->landlordParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 150,
            'rule_snapshot' => [],
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'account_id' => $arAccount->id,
            'debit_amount' => 150,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgRev->id,
            'account_id' => $revenueAccount->id,
            'debit_amount' => 0,
            'credit_amount' => 150,
            'currency_code' => 'GBP',
        ]);

        $key = 'settlement-idempotency-key-' . uniqid();
        $result1 = $settlementService->postSettlement(
            $this->project->id,
            $this->tenant->id,
            '2024-06-30',
            $key,
            '2024-06-30',
            false,
            null
        );
        $result2 = $settlementService->postSettlement(
            $this->project->id,
            $this->tenant->id,
            '2024-06-30',
            $key,
            '2024-06-30',
            false,
            null
        );

        $this->assertEquals($result1['posting_group']->id, $result2['posting_group']->id);
        $pgCount = PostingGroup::where('idempotency_key', $key)->where('tenant_id', $this->tenant->id)->count();
        $this->assertEquals(1, $pgCount);
    }
}
