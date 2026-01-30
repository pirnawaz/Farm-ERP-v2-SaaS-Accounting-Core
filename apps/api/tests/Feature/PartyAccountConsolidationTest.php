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
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Account;
use App\Models\AccountingCorrection;
use App\Services\InventoryPostingService;
use App\Services\PartyAccountService;
use App\Services\SystemAccountService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class PartyAccountConsolidationTest extends TestCase
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

    public function test_hari_operational_posting_uses_party_control_hari_only(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-HARI',
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
        $postingGroup = $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-15', 'issue-hari');

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->with('account')->get();
        $codes = $ledgerEntries->pluck('account.code')->unique()->values()->all();

        $this->assertContains('PARTY_CONTROL_HARI', $codes);
        $this->assertNotContains('DUE_FROM_HARI', $codes);
        $this->assertNotContains('PAYABLE_HARI', $codes);
        $this->assertNotContains('PROFIT_DISTRIBUTION', $codes);
    }

    public function test_party_account_service_resolves_control_account_by_party(): void
    {
        $partyAccountService = app(PartyAccountService::class);

        $hariAccount = $partyAccountService->getPartyControlAccount($this->tenant->id, $this->hariParty->id);
        $this->assertEquals('PARTY_CONTROL_HARI', $hariAccount->code);

        $landlordAccount = $partyAccountService->getPartyControlAccount($this->tenant->id, $this->landlordParty->id);
        $this->assertEquals('PARTY_CONTROL_LANDLORD', $landlordAccount->code);
    }

    public function test_party_account_service_resolves_control_account_by_role(): void
    {
        $partyAccountService = app(PartyAccountService::class);

        $hariAccount = $partyAccountService->getPartyControlAccountByRole($this->tenant->id, 'HARI');
        $this->assertEquals('PARTY_CONTROL_HARI', $hariAccount->code);

        $landlordAccount = $partyAccountService->getPartyControlAccountByRole($this->tenant->id, 'LANDLORD');
        $this->assertEquals('PARTY_CONTROL_LANDLORD', $landlordAccount->code);
    }

    public function test_consolidate_party_controls_moves_legacy_balances(): void
    {
        $accountService = app(SystemAccountService::class);
        
        // Get legacy accounts
        $advanceHari = $accountService->getByCode($this->tenant->id, 'ADVANCE_HARI');
        $dueFromHari = $accountService->getByCode($this->tenant->id, 'DUE_FROM_HARI');
        $payableHari = $accountService->getByCode($this->tenant->id, 'PAYABLE_HARI');
        $partyControlHari = $accountService->getByCode($this->tenant->id, 'PARTY_CONTROL_HARI');

        // Create a dummy posting group with legacy balances
        // ADVANCE_HARI: Dr 100, DUE_FROM_HARI: Dr 10, PAYABLE_HARI: Cr 40
        $legacyPg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL', // Valid enum value; simulates legacy balances
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-01-01',
        ]);

        // Create legacy ledger entries
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $legacyPg->id,
            'account_id' => $advanceHari->id,
            'debit_amount' => '100.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $legacyPg->id,
            'account_id' => $dueFromHari->id,
            'debit_amount' => '10.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $legacyPg->id,
            'account_id' => $payableHari->id,
            'debit_amount' => '0',
            'credit_amount' => '40.00',
            'currency_code' => 'GBP',
        ]);
        // Balancing entry to CASH
        $cashAccount = $accountService->getByCode($this->tenant->id, 'CASH');
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $legacyPg->id,
            'account_id' => $cashAccount->id,
            'debit_amount' => '0',
            'credit_amount' => '70.00',
            'currency_code' => 'GBP',
        ]);

        // Verify initial balances
        $this->assertEquals(100.00, $this->getAccountBalance($advanceHari->id, '2024-12-31'));
        $this->assertEquals(10.00, $this->getAccountBalance($dueFromHari->id, '2024-12-31'));
        $this->assertEquals(-40.00, $this->getAccountBalance($payableHari->id, '2024-12-31'));
        $this->assertEquals(0.00, $this->getAccountBalance($partyControlHari->id, '2024-12-31'));

        // Run consolidation command
        $exitCode = Artisan::call('accounting:consolidate-party-controls', [
            '--tenant' => $this->tenant->id,
            '--posting-date' => '2024-12-31',
        ]);
        $this->assertEquals(0, $exitCode, 'Consolidation command should succeed. Output: ' . Artisan::output());

        // Assert one consolidation PostingGroup created
        $consolidationPgs = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('correction_reason', AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION)
            ->get();
        $this->assertCount(1, $consolidationPgs);

        $consolidationPg = $consolidationPgs->first();
        $this->assertEquals('ACCOUNTING_CORRECTION', $consolidationPg->source_type);
        $this->assertNotNull($consolidationPg->source_id, 'Consolidation PostingGroup must have non-null source_id');
        $this->assertEquals('2024-12-31', $consolidationPg->posting_date->format('Y-m-d'));

        // Assert legacy accounts net to zero after consolidation
        $this->assertEqualsWithDelta(0.00, $this->getAccountBalance($advanceHari->id, '2024-12-31'), 0.01);
        $this->assertEqualsWithDelta(0.00, $this->getAccountBalance($dueFromHari->id, '2024-12-31'), 0.01);
        $this->assertEqualsWithDelta(0.00, $this->getAccountBalance($payableHari->id, '2024-12-31'), 0.01);

        // Assert PARTY_CONTROL_HARI net equals combined net (100 + 10 - 40 = Dr 70)
        $expectedNet = 100.00 + 10.00 - 40.00; // 70.00 debit
        $this->assertEqualsWithDelta($expectedNet, $this->getAccountBalance($partyControlHari->id, '2024-12-31'), 0.01);

        // Assert accounting_corrections record exists
        $correction = AccountingCorrection::where('tenant_id', $this->tenant->id)
            ->where('reason', AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION)
            ->first();
        $this->assertNotNull($correction);
        $this->assertNull($correction->original_posting_group_id);
        $this->assertNull($correction->reversal_posting_group_id);
        $this->assertEquals($consolidationPg->id, $correction->corrected_posting_group_id);

        // Assert idempotency: rerun does nothing
        $pgCountBefore = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('correction_reason', AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION)
            ->count();
        
        Artisan::call('accounting:consolidate-party-controls', [
            '--tenant' => $this->tenant->id,
            '--posting-date' => '2024-12-31',
        ]);

        $pgCountAfter = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('correction_reason', AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION)
            ->count();
        
        $this->assertEquals($pgCountBefore, $pgCountAfter);
    }

    private function getAccountBalance(string $accountId, string $asOfDate): float
    {
        $net = LedgerEntry::where('account_id', $accountId)
            ->whereHas('postingGroup', function ($q) use ($asOfDate) {
                $q->where('posting_date', '<=', $asOfDate);
            })
            ->selectRaw('COALESCE(SUM(debit_amount::numeric - credit_amount::numeric), 0) as net')
            ->value('net');

        return (float) $net;
    }
}
