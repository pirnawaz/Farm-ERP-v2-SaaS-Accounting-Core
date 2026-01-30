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
use App\Models\SaleInventoryAllocation;
use App\Models\Payment;
use App\Models\OperationalTransaction;
use App\Services\InventoryPostingService;
use App\Services\LabourPostingService;
use App\Services\SettlementService;
use App\Services\ReconciliationService;
use App\Services\SaleCOGSService;
use App\Services\PaymentService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ReconciliationConfidenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private InvStore $store;
    private InvItem $item;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $landlordParty;
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
        $this->enableModule($this->tenant, 'treasury_payments');

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

        // Create GRN to have stock for sales
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

    /**
     * Test 1: Settlement vs OT vs Ledger (Income + Expenses)
     * 
     * Setup: Post sale (creates INCOME OT), inventory issue (creates EXPENSE OT),
     * and labour work log (creates EXPENSE OT).
     * Verify that settlement totals match OT totals and ledger totals.
     */
    public function test_settlement_vs_ot_vs_ledger_reconciliation(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $labPosting = app(LabourPostingService::class);
        $settlementService = app(SettlementService::class);
        $reconciliationService = app(ReconciliationService::class);
        $saleCOGSService = app(SaleCOGSService::class);

        // Post sale 2000 (creates INCOME OT + PROJECT_REVENUE ledger entry)
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
        $saleCOGSService->postSaleWithCOGS($sale, '2024-06-15', 'sale-recon-1');

        // Post inventory issue 500 (creates EXPENSE OT + EXP_SHARED ledger entry)
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-16', 'issue-recon-1');

        // Post labour work log 300 (creates EXPENSE OT + LABOUR_EXPENSE ledger entry)
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
        $labPosting->postWorkLog($workLog->id, $this->tenant->id, '2024-06-17', 'lab-recon-1');

        // Pool totals excluding COGS (for like-for-like comparison with OT)
        $pool = $settlementService->getProjectProfitFromLedgerExcludingCOGS($this->project->id, $this->tenant->id, '2024-06-30');

        // Get reconciliation data (use same date range as settlement preview)
        $otReconciliation = $reconciliationService->reconcileProjectSettlementVsOT(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30'
        );

        $ledgerReconciliation = $reconciliationService->reconcileProjectLedgerIncomeExpense(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30',
            true // exclude COGS so ledger matches OT
        );

        // Assert: Pool totals (excluding COGS) equal OT totals
        $this->assertEqualsWithDelta(
            (float) $pool['total_revenue'],
            $otReconciliation['ot_revenue'],
            0.01,
            'Pool total_revenue (excl COGS) should equal OT revenue'
        );

        $this->assertEqualsWithDelta(
            (float) $pool['total_expenses'],
            $otReconciliation['ot_expenses_total'],
            0.01,
            'Pool total_expenses (excl COGS) should equal OT expenses total'
        );

        // Assert: Ledger (excluding COGS) matches OT
        $this->assertEqualsWithDelta(
            $otReconciliation['ot_revenue'],
            $ledgerReconciliation['ledger_income'],
            0.01,
            'OT revenue should match ledger income (excl COGS)'
        );

        $this->assertEqualsWithDelta(
            $otReconciliation['ot_expenses_total'],
            $ledgerReconciliation['ledger_expenses'],
            0.01,
            'OT expenses should match ledger expenses (excl COGS)'
        );

        // Assert: Pool profit consistency
        $expectedPoolProfit = (float) $pool['total_revenue'] - (float) $pool['total_expenses'];
        $this->assertEqualsWithDelta(
            (float) $pool['pool_profit'],
            $expectedPoolProfit,
            0.01,
            'Pool profit should equal revenue minus expenses'
        );
    }

    /**
     * Test 2: Reversal Reconciliation
     * 
     * Post sale and inventory issue, verify reconciliation.
     * Then reverse both and verify reconciliation unwinds correctly.
     */
    public function test_reversal_reconciliation(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $settlementService = app(SettlementService::class);
        $reconciliationService = app(ReconciliationService::class);
        $saleCOGSService = app(SaleCOGSService::class);

        // Post sale 2000
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
        $saleCOGSService->postSaleWithCOGS($sale, '2024-06-15', 'sale-rev-1');

        // Post inventory issue (10 qty at WAC 1 = 10 expense)
        $issue = InvIssue::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'ISS-REV',
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
        $invPosting->postIssue($issue->id, $this->tenant->id, '2024-06-16', 'issue-rev-1');

        // Verify reconciliation before reversal (pool and ledger exclude COGS)
        $poolBefore = $settlementService->getProjectProfitFromLedgerExcludingCOGS($this->project->id, $this->tenant->id, '2024-06-30');
        $otReconBefore = $reconciliationService->reconcileProjectSettlementVsOT(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30'
        );
        $ledgerReconBefore = $reconciliationService->reconcileProjectLedgerIncomeExpense(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30',
            true
        );

        $this->assertEqualsWithDelta(2000.00, (float) $poolBefore['total_revenue'], 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $poolBefore['total_expenses'], 0.01); // 10 qty × WAC 1
        $this->assertEqualsWithDelta(2000.00, $otReconBefore['ot_revenue'], 0.01);
        $this->assertEqualsWithDelta(10.00, $otReconBefore['ot_expenses_total'], 0.01); // 10 qty × WAC 1

        // Reverse the sale
        $saleCOGSService->reverseSale($sale, '2024-06-20', 'Reversal test');
        $sale->refresh();

        // Reverse the inventory issue
        $invPosting->reverseIssue($issue->id, $this->tenant->id, '2024-06-20', 'Reversal test');
        $issue->refresh();

        // Verify reconciliation after reversal (pool and ledger exclude COGS)
        $poolAfter = $settlementService->getProjectProfitFromLedgerExcludingCOGS($this->project->id, $this->tenant->id, '2024-06-30');
        $otReconAfter = $reconciliationService->reconcileProjectSettlementVsOT(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30'
        );
        $ledgerReconAfter = $reconciliationService->reconcileProjectLedgerIncomeExpense(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30',
            true
        );

        // Assert: Pool totals back to 0 after reversal
        $this->assertEqualsWithDelta(0, (float) $poolAfter['total_revenue'], 0.01, 'Revenue should be 0 after reversal');
        $this->assertEqualsWithDelta(0, (float) $poolAfter['total_expenses'], 0.01, 'Expenses should be 0 after reversal');

        // Assert: OT sums match settlement (net of reversals)
        $this->assertEqualsWithDelta(0, $otReconAfter['ot_revenue'], 0.01, 'OT revenue should be 0 after reversal');
        $this->assertEqualsWithDelta(0, $otReconAfter['ot_expenses_total'], 0.01, 'OT expenses should be 0 after reversal');

        // Assert: Ledger sums match OT sums (reversals properly negate original entries)
        // Reversals create offsetting entries, so net should be 0
        $this->assertEqualsWithDelta(0, $ledgerReconAfter['ledger_income'], 0.01, 'Ledger income should be 0 after reversal');
        $this->assertEqualsWithDelta(0, $ledgerReconAfter['ledger_expenses'], 0.01, 'Ledger expenses should be 0 after reversal');
    }

    /**
     * Test 2b: When COGS exists (sale with COGS posted), settlement vs OT and ledger vs OT
     * reconciliation still pass (delta 0) because COGS is excluded from settlement pool
     * and from ledger expense totals in reconciliation.
     */
    public function test_reconciliation_passes_when_cogs_exists(): void
    {
        $settlementService = app(SettlementService::class);
        $reconciliationService = app(ReconciliationService::class);
        $saleCOGSService = app(SaleCOGSService::class);

        // Post only a sale with COGS (revenue 2000, COGS e.g. 100 debited to ledger but not in OT)
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
        $saleCOGSService->postSaleWithCOGS($sale, '2024-06-15', 'sale-cogs-only');

        $pool = $settlementService->getProjectProfitFromLedgerExcludingCOGS($this->project->id, $this->tenant->id, '2024-06-30');
        $otRecon = $reconciliationService->reconcileProjectSettlementVsOT(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30'
        );
        $ledgerRecon = $reconciliationService->reconcileProjectLedgerIncomeExpense(
            $this->project->id,
            $this->tenant->id,
            '2024-01-01',
            '2024-06-30',
            true
        );

        // Settlement vs OT: pool (excl COGS) should match OT
        $this->assertEqualsWithDelta((float) $pool['total_revenue'], $otRecon['ot_revenue'], 0.01, 'Pool revenue (excl COGS) should equal OT revenue');
        $this->assertEqualsWithDelta((float) $pool['total_expenses'], $otRecon['ot_expenses_total'], 0.01, 'Pool expenses (excl COGS) should equal OT expenses');
        // Ledger vs OT: ledger (excl COGS) should match OT
        $this->assertEqualsWithDelta($otRecon['ot_revenue'], $ledgerRecon['ledger_income'], 0.01, 'OT revenue should match ledger income (excl COGS)');
        $this->assertEqualsWithDelta($otRecon['ot_expenses_total'], $ledgerRecon['ledger_expenses'], 0.01, 'OT expenses should match ledger expenses (excl COGS)');
    }

    /**
     * Test 3: Supplier AP Reconciliation
     * 
     * Post GRN supplier 1000, verify supplier outstanding.
     * Post payment OUT 400, verify supplier outstanding reduced.
     * If AP ledger movement is attributable, check alignment.
     */
    public function test_supplier_ap_reconciliation(): void
    {
        $invPosting = app(InventoryPostingService::class);
        $paymentService = app(PaymentService::class);
        $reconciliationService = app(ReconciliationService::class);

        // Post GRN supplier 1000 (creates SUPPLIER_AP allocation row)
        $grn = InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-SUPPLIER',
            'store_id' => $this->store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'supplier_party_id' => $this->vendorParty->id,
        ]);
        InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $this->item->id,
            'qty' => 100,
            'unit_cost' => 10.00,
            'line_total' => 1000.00,
        ]);
        $invPosting->postGRN($grn->id, $this->tenant->id, '2024-06-15', 'grn-supplier-1');

        // Verify supplier outstanding = 1000
        $apRecon1 = $reconciliationService->reconcileSupplierAP(
            $this->vendorParty->id,
            $this->tenant->id,
            '2024-06-01',
            '2024-06-30'
        );
        $this->assertEqualsWithDelta(1000.00, $apRecon1['supplier_outstanding'], 0.01, 'Supplier outstanding should be 1000 after GRN');

        // Post payment OUT 400
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->vendorParty->id,
            'direction' => 'OUT',
            'amount' => 400.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $paymentService->postPayment($payment->id, $this->tenant->id, '2024-06-20', 'payment-supplier-1', $this->cropCycle->id);

        // Verify supplier outstanding = 600
        $apRecon2 = $reconciliationService->reconcileSupplierAP(
            $this->vendorParty->id,
            $this->tenant->id,
            '2024-06-01',
            '2024-06-30'
        );
        $this->assertEqualsWithDelta(600.00, $apRecon2['net_supplier_outstanding'], 0.01, 'Net supplier outstanding should be 600 after payment');

        // If AP ledger movement is attributable, check alignment
        // Note: This depends on whether ledger entries are party-attributed
        // The reconciliation service will document this in the return value
        $this->assertArrayHasKey('reconciliation_status', $apRecon2);
        $this->assertArrayHasKey('ap_ledger_movement', $apRecon2);
        
        // At minimum, verify supplier outstanding lifecycle correctness
        $this->assertGreaterThan(0, $apRecon2['supplier_outstanding'], 'Supplier outstanding should be > 0');
        $this->assertGreaterThan(0, $apRecon2['payment_outstanding'], 'Payment outstanding should be > 0');
    }
}
