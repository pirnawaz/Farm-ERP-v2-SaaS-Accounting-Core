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
use App\Services\Accounting\PostValidationService;
use App\Services\InventoryPostingService;
use App\Services\SettlementService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

/**
 * Accounting guardrails: deprecated account codes must not receive new ledger entries.
 * PostValidationService is called before persisting; posting flows use PARTY_CONTROL_* only.
 */
class AccountingDeprecatedAccountsGuardTest extends TestCase
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

    /** @test */
    public function validate_no_deprecated_accounts_throws_when_ledger_line_uses_deprecated_code(): void
    {
        $payableHari = Account::where('tenant_id', $this->tenant->id)->where('code', 'PAYABLE_HARI')->first();
        $this->assertNotNull($payableHari, 'PAYABLE_HARI account must exist for test');

        $validator = app(PostValidationService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PAYABLE_HARI');
        $this->expectExceptionMessage('deprecated');

        $validator->validateNoDeprecatedAccounts($this->tenant->id, [
            ['account_id' => $payableHari->id, 'debit_amount' => 100, 'credit_amount' => 0],
        ]);
    }

    /** @test */
    public function inventory_issue_posting_succeeds_and_does_not_use_deprecated_accounts(): void
    {
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-DEP',
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
        $postingGroup = $postingService->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-dep-guard');

        $deprecatedCodes = config('accounting.deprecated_codes', []);
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->with('account')->get();
        foreach ($ledgerEntries as $entry) {
            $code = $entry->account->code ?? null;
            $this->assertNotContains($code, $deprecatedCodes, "Inventory Issue must not post to deprecated account: {$code}");
        }
        $this->assertCount(2, $ledgerEntries);
    }

    /** @test */
    public function settlement_posting_succeeds_and_does_not_use_deprecated_accounts(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);

        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-SET',
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-set-1');

        $revenueAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();
        $pgRev = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $this->project->id,
            'posting_date' => '2024-06-14',
            'idempotency_key' => 'test-rev-dep-1',
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
            'account_id' => $cashAccount->id,
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
            'sett-dep-guard-1',
            '2024-06-30',
            false,
            null
        );

        $postingGroup = $result['posting_group'];
        $deprecatedCodes = config('accounting.deprecated_codes', []);
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->with('account')->get();
        foreach ($ledgerEntries as $entry) {
            $code = $entry->account->code ?? null;
            $this->assertNotContains($code, $deprecatedCodes, "Settlement must not post to deprecated account: {$code}");
        }
        $this->assertNotEmpty($ledgerEntries);
    }
}
