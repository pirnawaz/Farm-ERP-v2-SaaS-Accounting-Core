<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\ShareRule;
use App\Models\ShareRuleLine;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\SettlementSale;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Account;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Services\TenantContext;
use App\Services\SettlementService;
use App\Services\ShareRuleService;
use App\Services\SaleCOGSService;
use App\Services\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private function enableSettlements(Tenant $tenant): void
    {
        $m = Module::where('key', 'settlements')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function enableARSales(Tenant $tenant): void
    {
        $m = Module::where('key', 'ar_sales')->first();
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

    private function headers(string $role = 'accountant'): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Party $landlordParty;
    private Party $growerParty;
    private InvItem $invItem;
    private InvStore $invStore;
    private Project $project;
    private Account $settlementClearingAccount;
    private Account $accountsPayableAccount;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableSettlements($this->tenant);
        $this->enableARSales($this->tenant);
        $this->enableInventory($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->landlordParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);

        $this->growerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Grower',
            'party_types' => ['GROWER'],
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->invItem = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Item',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $this->invStore = InvStore::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $this->invStore->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $this->invItem->id,
            'qty' => 200,
            'unit_cost' => 6.00,
            'line_total' => 1200.00,
        ]);
        app(InventoryPostingService::class)->postGRN($grn->id, $this->tenant->id, '2024-06-01', 'grn-1');

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->landlordParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        // Create settlement accounts if they don't exist (clearing = equity per DB constraint)
        $this->settlementClearingAccount = Account::firstOrCreate(
            [
                'tenant_id' => $this->tenant->id,
                'code' => 'SETTLEMENT_CLEARING',
            ],
            [
                'name' => 'Settlement Clearing',
                'type' => 'equity',
            ]
        );

        $this->accountsPayableAccount = Account::firstOrCreate(
            [
                'tenant_id' => $this->tenant->id,
                'code' => 'ACCOUNTS_PAYABLE',
            ],
            [
                'name' => 'Accounts Payable',
                'type' => 'liability',
            ]
        );
    }

    private function createPostedSale(float $revenue, float $cogs): Sale
    {
        $buyerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $buyerParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'amount' => $revenue,
            'posting_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->invItem->id,
            'store_id' => $this->invStore->id,
            'quantity' => 100,
            'unit_price' => $revenue / 100,
            'line_total' => $revenue,
        ]);

        app(SaleCOGSService::class)->postSaleWithCOGS($sale, '2024-06-01', 'test-key-' . uniqid());

        return $sale->fresh();
    }

    public function test_preview_returns_correct_distribution(): void
    {
        // Create posted sale with margin
        $sale = $this->createPostedSale(1000.00, 600.00); // Revenue: 1000, COGS: 600, Margin: 400

        // Create share rule (70% landlord, 30% grower)
        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 70.00,
            'role' => 'LANDLORD',
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->growerParty->id,
            'percentage' => 30.00,
            'role' => 'GROWER',
        ]);

        $settlementService = app(SettlementService::class);

        $preview = $settlementService->preview([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31',
            'share_rule_id' => $shareRule->id,
        ]);

        $this->assertEquals(1000.00, $preview['total_revenue']);
        $this->assertEquals(600.00, $preview['total_cogs']);
        $this->assertEquals(400.00, $preview['total_margin']);
        $this->assertEquals(400.00, $preview['basis_amount']); // Based on margin

        $landlordAmount = collect($preview['party_amounts'])->firstWhere('party_id', $this->landlordParty->id);
        $growerAmount = collect($preview['party_amounts'])->firstWhere('party_id', $this->growerParty->id);

        $this->assertEquals(280.00, $landlordAmount['amount']); // 70% of 400
        $this->assertEquals(120.00, $growerAmount['amount']); // 30% of 400
    }

    public function test_post_creates_payables(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);

        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 70.00,
            'role' => 'LANDLORD',
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->growerParty->id,
            'percentage' => 30.00,
            'role' => 'GROWER',
        ]);

        $settlementService = app(SettlementService::class);

        // Create settlement
        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31',
        ]);

        $this->assertEquals('DRAFT', $settlement->status);

        // Post settlement
        $result = $settlementService->post($settlement, '2024-06-15');

        $settlement = $result['settlement'];
        $postingGroup = $result['posting_group'];

        $this->assertEquals('POSTED', $settlement->status);
        $this->assertNotNull($settlement->posting_group_id);

        // Verify posting group exists
        $this->assertNotNull($postingGroup);
        $this->assertEquals('SETTLEMENT', $postingGroup->source_type);
        $this->assertEquals($settlement->id, $postingGroup->source_id);

        // Verify allocation rows
        $allocationRows = $postingGroup->allocationRows;
        $this->assertCount(2, $allocationRows); // One per party

        $landlordAllocation = $allocationRows->firstWhere('party_id', $this->landlordParty->id);
        $growerAllocation = $allocationRows->firstWhere('party_id', $this->growerParty->id);

        $this->assertEquals('SETTLEMENT_PAYABLE', $landlordAllocation->allocation_type);
        $this->assertEquals(280.00, (float) $landlordAllocation->amount);
        $this->assertEquals(120.00, (float) $growerAllocation->amount);

        // Verify ledger entries are balanced
        $ledgerEntries = $postingGroup->ledgerEntries;
        $totalDebits = $ledgerEntries->sum('debit_amount');
        $totalCredits = $ledgerEntries->sum('credit_amount');

        $this->assertEqualsWithDelta($totalDebits, $totalCredits, 0.01);

        // Verify AP credits per party
        $apCredits = $ledgerEntries->where('account_id', $this->accountsPayableAccount->id)
            ->sum('credit_amount');
        $this->assertEquals(400.00, (float) $apCredits); // Total of all party amounts
    }

    public function test_reverse_settlement(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);

        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 100.00,
            'role' => 'LANDLORD',
        ]);

        $settlementService = app(SettlementService::class);

        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
        ]);

        $result = $settlementService->post($settlement, '2024-06-15');
        $settlement = $result['settlement'];

        // Reverse settlement
        $reverseResult = $settlementService->reverse($settlement, '2024-06-20');

        $settlement = $reverseResult['settlement'];
        $reversalPostingGroup = $reverseResult['reversal_posting_group'];

        $this->assertEquals('REVERSED', $settlement->status);
        $this->assertNotNull($settlement->reversal_posting_group_id);
        $this->assertEquals('REVERSAL', $reversalPostingGroup->source_type);

        // Verify reversal entries negate original
        $originalEntries = PostingGroup::find($settlement->posting_group_id)->ledgerEntries;
        $reversalEntries = $reversalPostingGroup->ledgerEntries;

        foreach ($originalEntries as $original) {
            $reversal = $reversalEntries->firstWhere('account_id', $original->account_id);
            $this->assertNotNull($reversal);
            $this->assertEquals((float) $original->debit_amount, (float) $reversal->credit_amount);
            $this->assertEquals((float) $original->credit_amount, (float) $reversal->debit_amount);
        }
    }

    public function test_idempotent_post(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);

        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 100.00,
            'role' => 'LANDLORD',
        ]);

        $settlementService = app(SettlementService::class);

        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
        ]);

        // Post first time
        $result1 = $settlementService->post($settlement, '2024-06-15');
        $postingGroupId1 = $result1['posting_group']->id;

        // Post second time (should return same posting group)
        $result2 = $settlementService->post($settlement->fresh(), '2024-06-15');
        $postingGroupId2 = $result2['posting_group']->id;

        $this->assertEquals($postingGroupId1, $postingGroupId2);

        // Verify only one posting group exists
        $postingGroups = PostingGroup::where('source_type', 'SETTLEMENT')
            ->where('source_id', $settlement->id)
            ->get();
        $this->assertCount(1, $postingGroups);
    }

    public function test_cannot_settle_unposted_sales(): void
    {
        $buyerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $buyerParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 1000.00,
            'posting_date' => '2024-06-01',
            'status' => 'DRAFT', // Not posted
        ]);

        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 100.00,
            'role' => 'LANDLORD',
        ]);

        $settlementService = app(SettlementService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('All sales must be POSTED');

        $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
        ]);
    }

    public function test_cannot_settle_already_settled_sales(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);

        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);

        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 100.00,
            'role' => 'LANDLORD',
        ]);

        $settlementService = app(SettlementService::class);

        // Create and post first settlement
        $settlement1 = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
        ]);

        $settlementService->post($settlement1, '2024-06-15');

        // Try to create second settlement with same sale
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already settled');

        $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
        ]);
    }

    public function test_share_rule_percentages_must_sum_to_100(): void
    {
        $shareRuleService = app(ShareRuleService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must sum to 100');

        $shareRuleService->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invalid Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'lines' => [
                [
                    'party_id' => $this->landlordParty->id,
                    'percentage' => 60.00,
                    'role' => 'LANDLORD',
                ],
                [
                    'party_id' => $this->growerParty->id,
                    'percentage' => 30.00, // Total is 90, not 100
                    'role' => 'GROWER',
                ],
            ],
        ]);
    }

    /** DRAFT sales-based settlement may have posting_group_id NULL (guardrail allows it). */
    public function test_draft_sales_based_settlement_may_have_null_posting_group_id(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);
        $shareRule = $this->createShareRule();
        $settlementService = app(SettlementService::class);

        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31',
        ]);

        $this->assertEquals('DRAFT', $settlement->status);
        $this->assertNull($settlement->posting_group_id);
    }

    /** Posting sets status POSTED and posting_group_id (guardrail requires NOT NULL when POSTED). */
    public function test_post_sets_posting_group_id(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);
        $shareRule = $this->createShareRule();
        $settlementService = app(SettlementService::class);

        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31',
        ]);
        $result = $settlementService->post($settlement, '2024-06-15');

        $settlement->refresh();
        $this->assertEquals('POSTED', $settlement->status);
        $this->assertNotNull($settlement->posting_group_id);
        $this->assertNotNull($result['posting_group']);
    }

    /** DB CHECK constraint rejects status POSTED with posting_group_id NULL. */
    public function test_constraint_rejects_posted_with_null_posting_group_id(): void
    {
        $sale = $this->createPostedSale(1000.00, 600.00);
        $shareRule = $this->createShareRule();
        $settlementService = app(SettlementService::class);

        $settlement = $settlementService->create([
            'tenant_id' => $this->tenant->id,
            'sale_ids' => [$sale->id],
            'share_rule_id' => $shareRule->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'from_date' => '2024-01-01',
            'to_date' => '2024-12-31',
        ]);
        $this->assertNull($settlement->posting_group_id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('settlements')
            ->where('id', $settlement->id)
            ->update(['status' => 'POSTED', 'posting_group_id' => null]);
    }

    private function createShareRule(): ShareRule
    {
        $shareRule = ShareRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Rule',
            'applies_to' => 'CROP_CYCLE',
            'basis' => 'MARGIN',
            'effective_from' => '2024-01-01',
            'is_active' => true,
            'version' => 1,
        ]);
        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->landlordParty->id,
            'percentage' => 70.00,
            'role' => 'LANDLORD',
        ]);
        ShareRuleLine::create([
            'share_rule_id' => $shareRule->id,
            'party_id' => $this->growerParty->id,
            'percentage' => 30.00,
            'role' => 'GROWER',
        ]);
        return $shareRule;
    }
}
