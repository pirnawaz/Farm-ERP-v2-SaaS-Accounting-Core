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
use App\Models\SaleInventoryAllocation;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\InvStockBalance;
use App\Models\Account;
use App\Services\TenantContext;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class SaleMarginTest extends TestCase
{
    use RefreshDatabase;

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

    private function headers(string $role = 'accountant'): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;
    private Party $buyerParty;
    private InvItem $item;
    private InvStore $store;
    private Account $cropWipAccount;
    private Account $inventoryAccount;
    private Account $cogsAccount;
    private Account $arAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableARSales($this->tenant);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Party',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $this->buyerParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Buyer Party',
            'party_types' => ['BUYER'],
        ]);

        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Produce']);
        $this->item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat',
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

        $this->cropWipAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CROP_WIP')->first();
        $this->inventoryAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'INVENTORY_PRODUCE')->first();
        $this->cogsAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'COGS_PRODUCE')->first();
        $this->arAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'AR')->first();
        $this->revenueAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->first();
    }

    private function createWipCost(float $amount): void
    {
        $postingService = app(PostingService::class);
        $postingService->postOperationalTransaction([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'amount' => $amount,
            'posting_date' => '2024-06-01',
            'classification' => 'SHARED',
        ], 'test-idempotency-' . uniqid());
    }

    private function createHarvestWithInventory(float $qty, float $cost): Harvest
    {
        $this->createWipCost($cost);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => $qty,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        return $harvest->fresh();
    }

    public function test_sale_post_blocks_if_insufficient_produce_inventory(): void
    {
        // No harvest posted (no inventory)
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 100,
            'posting_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 5,
            'unit_price' => 20,
            'line_total' => 100,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'test-key-1',
            ]);

        $post->assertStatus(422);
        $this->assertStringContainsString('insufficient stock', strtolower($post->json('error') ?? ''));

        $sale->refresh();
        $this->assertEquals('DRAFT', $sale->status);
        $this->assertNull($sale->posting_group_id);

        // Verify no posting group created
        $postingGroup = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'SALE')
            ->where('source_id', $sale->id)
            ->first();
        $this->assertNull($postingGroup);
    }

    public function test_sale_post_creates_cogs_and_reduces_inventory(): void
    {
        // Setup: Post operational cost and harvest
        $harvest = $this->createHarvestWithInventory(10, 100);
        // Harvest moves 100 into produce inventory; unit_cost = 10

        // Create sale for qty 4 at price 20 each (revenue 80)
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 80,
            'posting_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 4,
            'unit_price' => 20,
            'line_total' => 80,
        ]);

        // Check inventory before posting
        $balanceBefore = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(10, (float) $balanceBefore->qty_on_hand);

        // POST sale
        $post = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'test-key-2',
            ]);

        $post->assertStatus(201);
        $sale->refresh();

        // Assert sale posting group exists and is balanced
        $this->assertNotNull($sale->posting_group_id);
        $postingGroup = PostingGroup::find($sale->posting_group_id);
        $this->assertNotNull($postingGroup);
        $this->assertEquals('SALE', $postingGroup->source_type);

        // Verify ledger entries balance
        $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('debit_amount');
        $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)->sum('credit_amount');
        $this->assertEqualsWithDelta((float) $totalDebits, (float) $totalCredits, 0.01);

        // Assert ledger entries: Cr INVENTORY_PRODUCE 40, Dr COGS_PRODUCE 40
        $inventoryEntry = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->where('account_id', $this->inventoryAccount->id)
            ->first();
        $this->assertNotNull($inventoryEntry);
        $this->assertEquals(0, (float) $inventoryEntry->debit_amount);
        $this->assertEquals(40, (float) $inventoryEntry->credit_amount);

        $cogsEntry = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->where('account_id', $this->cogsAccount->id)
            ->first();
        $this->assertNotNull($cogsEntry);
        $this->assertEquals(40, (float) $cogsEntry->debit_amount);
        $this->assertEquals(0, (float) $cogsEntry->credit_amount);

        // Assert revenue entries: Dr AR 80, Cr PROJECT_REVENUE 80
        $arEntry = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->where('account_id', $this->arAccount->id)
            ->first();
        $this->assertNotNull($arEntry);
        $this->assertEquals(80, (float) $arEntry->debit_amount);

        $revenueEntry = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->where('account_id', $this->revenueAccount->id)
            ->first();
        $this->assertNotNull($revenueEntry);
        $this->assertEquals(80, (float) $revenueEntry->credit_amount);

        // Assert inventory on-hand decreased from 10 to 6
        $balanceAfter = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(6, (float) $balanceAfter->qty_on_hand);
        $this->assertEquals(60, (float) $balanceAfter->value_on_hand);

        // Assert sale_inventory_allocations exists
        $allocation = SaleInventoryAllocation::where('tenant_id', $this->tenant->id)
            ->where('sale_id', $sale->id)
            ->first();
        $this->assertNotNull($allocation);
        $this->assertEquals(4, (float) $allocation->quantity);
        $this->assertEquals(10, (float) $allocation->unit_cost);
        $this->assertEquals(40, (float) $allocation->total_cost);
        $this->assertEquals('WAC', $allocation->costing_method);

        // Assert AllocationRow exists with allocation_type='SALE_COGS'
        $cogsAllocation = AllocationRow::where('posting_group_id', $postingGroup->id)
            ->where('allocation_type', 'SALE_COGS')
            ->first();
        $this->assertNotNull($cogsAllocation);
        $this->assertEquals(40, (float) $cogsAllocation->amount);
    }

    public function test_sale_reverse_restores_inventory_and_reverses_cogs(): void
    {
        // Setup: Post harvest and sale
        $harvest = $this->createHarvestWithInventory(10, 100);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 80,
            'posting_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 4,
            'unit_price' => 20,
            'line_total' => 80,
        ]);

        // Post sale
        $post = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'test-key-3',
            ]);
        $post->assertStatus(201);
        $sale->refresh();

        // Check inventory after posting (should be 6)
        $balanceAfterPost = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(6, (float) $balanceAfterPost->qty_on_hand);

        // Reverse sale
        $reverse = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-21',
                'reason' => 'Correction',
            ]);

        $reverse->assertStatus(200);
        $sale->refresh();

        // Assert reversal posting group exists
        $this->assertEquals('REVERSED', $sale->status);
        $this->assertNotNull($sale->reversal_posting_group_id);

        $reversalPG = PostingGroup::find($sale->reversal_posting_group_id);
        $this->assertNotNull($reversalPG);
        $this->assertEquals('REVERSAL', $reversalPG->source_type);

        // Assert inventory on-hand restored to 10
        $balanceAfterReverse = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(10, (float) $balanceAfterReverse->qty_on_hand);
        $this->assertEquals(100, (float) $balanceAfterReverse->value_on_hand);

        // Assert reversal ledger entries flip COGS/inventory
        $reversalCogsEntry = LedgerEntry::where('posting_group_id', $reversalPG->id)
            ->where('account_id', $this->cogsAccount->id)
            ->first();
        $this->assertNotNull($reversalCogsEntry);
        $this->assertEquals(0, (float) $reversalCogsEntry->debit_amount);
        $this->assertEquals(40, (float) $reversalCogsEntry->credit_amount);

        $reversalInventoryEntry = LedgerEntry::where('posting_group_id', $reversalPG->id)
            ->where('account_id', $this->inventoryAccount->id)
            ->first();
        $this->assertNotNull($reversalInventoryEntry);
        $this->assertEquals(40, (float) $reversalInventoryEntry->debit_amount);
        $this->assertEquals(0, (float) $reversalInventoryEntry->credit_amount);
    }

    public function test_idempotent_sale_post(): void
    {
        // Setup: Post harvest
        $harvest = $this->createHarvestWithInventory(10, 100);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 80,
            'posting_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 4,
            'unit_price' => 20,
            'line_total' => 80,
        ]);

        $idempotencyKey = 'test-idempotency-' . uniqid();

        // Call POST twice
        $post1 = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => $idempotencyKey,
            ]);
        $post1->assertStatus(201);

        $post2 = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => $idempotencyKey,
            ]);
        $post2->assertStatus(201);

        // Assert only one SALE posting group exists
        $postingGroups = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'SALE')
            ->where('source_id', $sale->id)
            ->get();
        $this->assertEquals(1, $postingGroups->count());

        // Assert only one allocation row per sale line exists
        $allocations = SaleInventoryAllocation::where('tenant_id', $this->tenant->id)
            ->where('sale_id', $sale->id)
            ->get();
        $this->assertEquals(1, $allocations->count());

        // Assert inventory reduced only once (should be 6, not 2)
        $balance = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(6, (float) $balance->qty_on_hand);
    }

    public function test_sales_margin_report_returns_correct_values(): void
    {
        // Setup: Post harvest and sale
        $harvest = $this->createHarvestWithInventory(10, 100);

        $sale = Sale::create([
            'tenant_id' => $this->tenant->id,
            'buyer_party_id' => $this->buyerParty->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'amount' => 80,
            'posting_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        SaleLine::create([
            'tenant_id' => $this->tenant->id,
            'sale_id' => $sale->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 4,
            'unit_price' => 20,
            'line_total' => 80,
        ]);

        // Post sale
        $post = $this->withHeaders($this->headers())
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'test-key-5',
            ]);
        $post->assertStatus(201);

        // Call margin report
        $report = $this->withHeaders($this->headers())
            ->getJson("/api/reports/sales-margin?crop_cycle_id={$this->cropCycle->id}");

        $report->assertStatus(200);
        $data = $report->json();

        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));

        $row = $data[0];
        $this->assertEquals(80, (float) $row['revenue_total']);
        $this->assertEquals(40, (float) $row['cogs_total']);
        $this->assertEquals(40, (float) $row['gross_margin']);
        $this->assertEquals(50, (float) $row['gross_margin_pct']);
        $this->assertEquals(4, (float) $row['qty_sold']);
    }
}
