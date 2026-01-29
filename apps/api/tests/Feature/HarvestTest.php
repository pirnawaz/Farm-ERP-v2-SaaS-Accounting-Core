<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
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
use App\Models\InvStockMovement;
use App\Models\Account;
use App\Services\TenantContext;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class HarvestTest extends TestCase
{
    use RefreshDatabase;

    private function enableCropOps(Tenant $tenant): void
    {
        $m = Module::where('key', 'crop_ops')->first();
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
    private InvItem $item;
    private InvStore $store;
    private Account $cropWipAccount;
    private Account $inventoryAccount;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableCropOps($this->tenant);

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
    }

    public function test_can_create_draft_harvest_and_lines(): void
    {
        $create = $this->withHeaders($this->headers())
            ->postJson('/api/v1/crop-ops/harvests', [
                'crop_cycle_id' => $this->cropCycle->id,
                'project_id' => $this->project->id,
                'harvest_date' => '2024-06-15',
                'notes' => 'Test harvest',
            ]);

        $create->assertStatus(201);
        $harvestId = $create->json('id');
        $this->assertEquals('DRAFT', $create->json('status'));

        $addLine = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvestId}/lines", [
                'inventory_item_id' => $this->item->id,
                'store_id' => $this->store->id,
                'quantity' => 10,
                'uom' => 'KG',
            ]);

        $addLine->assertStatus(201);
        $this->assertEquals(10, (float) $addLine->json('quantity'));

        $harvest = Harvest::find($harvestId);
        $this->assertNotNull($harvest);
        $this->assertEquals('DRAFT', $harvest->status);
        $this->assertEquals(1, $harvest->lines->count());
    }

    public function test_cannot_edit_after_post(): void
    {
        // Create and post harvest
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
            'quantity' => 10,
        ]);

        // Create WIP cost first
        $this->createWipCost(100);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();
        $this->assertEquals('POSTED', $harvest->status);

        // Try to update
        $update = $this->withHeaders($this->headers())
            ->putJson("/api/v1/crop-ops/harvests/{$harvest->id}", [
                'notes' => 'Updated notes',
            ]);

        $update->assertStatus(422);

        // Try to add line
        $addLine = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/lines", [
                'inventory_item_id' => $this->item->id,
                'store_id' => $this->store->id,
                'quantity' => 5,
            ]);

        $addLine->assertStatus(422);
    }

    public function test_post_creates_balanced_posting_group_and_moves_wip_to_inventory(): void
    {
        // Create WIP cost
        $this->createWipCost(100);

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
            'quantity' => 10,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();

        $this->assertEquals('POSTED', $harvest->status);
        $this->assertNotNull($harvest->posting_group_id);

        $postingGroup = PostingGroup::find($harvest->posting_group_id);
        $this->assertNotNull($postingGroup);
        $this->assertEquals('HARVEST', $postingGroup->source_type);

        // Check ledger entries
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(2, $ledgerEntries);

        $wipEntry = $ledgerEntries->firstWhere('account_id', $this->cropWipAccount->id);
        $this->assertNotNull($wipEntry);
        $this->assertEquals(0, (float) $wipEntry->debit_amount);
        $this->assertEquals(100, (float) $wipEntry->credit_amount);

        $invEntry = $ledgerEntries->firstWhere('account_id', $this->inventoryAccount->id);
        $this->assertNotNull($invEntry);
        $this->assertEquals(100, (float) $invEntry->debit_amount);
        $this->assertEquals(0, (float) $invEntry->credit_amount);

        // Check balance
        $totalDebits = $ledgerEntries->sum('debit_amount');
        $totalCredits = $ledgerEntries->sum('credit_amount');
        $this->assertEquals($totalDebits, $totalCredits);

        // Check inventory on-hand increased
        $balance = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals(10, (float) $balance->qty_on_hand);
        $this->assertEquals(100, (float) $balance->value_on_hand);
    }

    public function test_post_fails_if_crop_cycle_closed(): void
    {
        $closedCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Cycle',
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
            'status' => 'CLOSED',
        ]);

        $closedProject = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->project->party_id,
            'crop_cycle_id' => $closedCycle->id,
            'name' => 'Closed Project',
            'status' => 'ACTIVE',
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $closedCycle->id,
            'project_id' => $closedProject->id,
            'harvest_date' => '2023-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2023-06-15',
            ]);

        $post->assertStatus(422);
        $this->assertStringContainsString('closed', strtolower($post->json('error') ?? ''));

        $harvest->refresh();
        $this->assertEquals('DRAFT', $harvest->status);
        $this->assertNull($harvest->posting_group_id);
    }

    public function test_reverse_creates_reversal_posting_and_reverts_inventory(): void
    {
        // Create WIP cost and post harvest
        $this->createWipCost(100);

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
            'quantity' => 10,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();

        // Check inventory before reversal
        $balanceBefore = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(10, (float) $balanceBefore->qty_on_hand);

        // Reverse
        $reverse = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/reverse", [
                'reversal_date' => '2024-06-16',
                'reason' => 'Correction',
            ]);

        $reverse->assertStatus(200);
        $harvest->refresh();

        $this->assertEquals('REVERSED', $harvest->status);
        $this->assertNotNull($harvest->reversal_posting_group_id);

        // Check reversal ledger entries
        $reversalPG = PostingGroup::find($harvest->reversal_posting_group_id);
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPG->id)->get();

        $wipReversal = $reversalEntries->firstWhere('account_id', $this->cropWipAccount->id);
        $this->assertNotNull($wipReversal);
        $this->assertEquals(100, (float) $wipReversal->debit_amount); // Flipped
        $this->assertEquals(0, (float) $wipReversal->credit_amount);

        // Check inventory decreased
        $balanceAfter = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(0, (float) $balanceAfter->qty_on_hand);
    }

    public function test_idempotency_posting_twice_returns_existing(): void
    {
        $this->createWipCost(100);

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
            'quantity' => 10,
        ]);

        $post1 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post1->assertStatus(200);
        $pgId1 = $post1->json('posting_group_id');

        $post2 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post2->assertStatus(200);
        $pgId2 = $post2->json('posting_group_id');

        $this->assertEquals($pgId1, $pgId2);

        // Check only one HARVEST posting group exists
        $harvestPGs = PostingGroup::where('tenant_id', $this->tenant->id)
            ->where('source_type', 'HARVEST')
            ->where('source_id', $harvest->id)
            ->get();
        $this->assertCount(1, $harvestPGs);
    }

    public function test_cost_allocation_proportional_by_quantity(): void
    {
        $this->createWipCost(300);

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
            'quantity' => 10,
        ]);

        $item2 = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rice',
            'uom_id' => $this->item->uom_id,
            'category_id' => $this->item->category_id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item2->id,
            'store_id' => $this->store->id,
            'quantity' => 20,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();

        $postingGroup = PostingGroup::find($harvest->posting_group_id);
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();

        $this->assertCount(2, $allocationRows);

        $line1Allocation = $allocationRows->firstWhere(function ($ar) use ($harvest) {
            $line = $harvest->lines->first();
            return $ar->rule_snapshot['line_index'] === 0;
        });
        $line2Allocation = $allocationRows->firstWhere(function ($ar) use ($harvest) {
            return $ar->rule_snapshot['line_index'] === 1;
        });

        $this->assertNotNull($line1Allocation);
        $this->assertNotNull($line2Allocation);

        // Line 1: 10/30 * 300 = 100
        // Line 2: 20/30 * 300 = 200
        $this->assertEquals(100, (float) $line1Allocation->amount, 'Line 1 should get 100', 0.01);
        $this->assertEquals(200, (float) $line2Allocation->amount, 'Line 2 should get 200', 0.01);
    }

    public function test_cost_allocation_equal_when_zero_quantity(): void
    {
        // This test verifies that zero quantity is rejected by validation
        // Since DB constraint prevents creating lines with quantity = 0,
        // we test by attempting to add a line with 0 quantity via API
        $this->createWipCost(200);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        // Attempt to add line with zero quantity via API - should fail validation
        $addLine = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/lines", [
                'inventory_item_id' => $this->item->id,
                'store_id' => $this->store->id,
                'quantity' => 0,
            ]);

        // Should fail validation (422) because quantity must be > 0
        $addLine->assertStatus(422);
    }

    /**
     * Helper to create WIP cost by creating a direct posting that debits CROP_WIP
     * This simulates accumulated costs in WIP for testing purposes
     */
    private function createWipCost(float $amount): void
    {
        // Create a posting group that debits CROP_WIP (simulating accumulated costs)
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => \Illuminate\Support\Str::uuid(), // Dummy source_id for test
            'posting_date' => '2024-05-01',
            'idempotency_key' => 'wip-test-' . uniqid(),
        ]);

        $cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();

        // Debit CROP_WIP (accumulate cost)
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->cropWipAccount->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);

        // Credit CASH (source of cost)
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_harvest_debits_produce_inventory_not_inputs(): void
    {
        // Verify harvest debits INVENTORY_PRODUCE, not INVENTORY_INPUTS
        $this->createWipCost(100);

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
            'quantity' => 10,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();

        $postingGroup = PostingGroup::find($harvest->posting_group_id);
        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();

        // Check that INVENTORY_PRODUCE is debited
        $produceAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'INVENTORY_PRODUCE')->first();
        $produceEntry = $ledgerEntries->firstWhere('account_id', $produceAccount->id);
        $this->assertNotNull($produceEntry, 'INVENTORY_PRODUCE should be debited');
        $this->assertEquals(100, (float) $produceEntry->debit_amount);

        // Check that INVENTORY_INPUTS is NOT affected
        $inputsAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'INVENTORY_INPUTS')->first();
        $inputsEntry = $ledgerEntries->firstWhere('account_id', $inputsAccount->id);
        $this->assertNull($inputsEntry, 'INVENTORY_INPUTS should not be affected by harvest');

        // Verify INVENTORY_INPUTS balance unchanged
        $inputsBalanceBefore = LedgerEntry::where('tenant_id', $this->tenant->id)
            ->where('account_id', $inputsAccount->id)
            ->sum(DB::raw('debit_amount - credit_amount'));
        
        // No change expected (no entries created)
        $this->assertEquals(0, (float) $inputsBalanceBefore);
    }

    public function test_multiple_harvests_do_not_double_transfer_wip(): void
    {
        // Setup: Create crop cycle with WIP cost
        $this->createWipCost(100);

        // Harvest 1: Should transfer full WIP (100)
        $harvest1 = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest1->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 5,
        ]);

        $post1 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest1->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post1->assertStatus(200);
        $harvest1->refresh();

        $pg1 = PostingGroup::find($harvest1->posting_group_id);
        $wipCredit1 = LedgerEntry::where('posting_group_id', $pg1->id)
            ->where('account_id', $this->cropWipAccount->id)
            ->sum('credit_amount');
        $this->assertEquals(100, (float) $wipCredit1, 'Harvest 1 should transfer full WIP (100)');

        // Harvest 2: Should transfer remaining WIP (0, since Harvest 1 already credited 100)
        $harvest2 = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-20',
            'status' => 'DRAFT',
        ]);

        HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest2->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 5,
        ]);

        $post2 = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest2->id}/post", [
                'posting_date' => '2024-06-20',
            ]);

        $post2->assertStatus(200);
        $harvest2->refresh();

        $pg2 = PostingGroup::find($harvest2->posting_group_id);
        $wipCredit2 = LedgerEntry::where('posting_group_id', $pg2->id)
            ->where('account_id', $this->cropWipAccount->id)
            ->sum('credit_amount');
        $this->assertEquals(0, (float) $wipCredit2, 'Harvest 2 should transfer 0 (WIP already transferred)');

        // Total credits to CROP_WIP across both harvests = 100 (not 200)
        $totalWipCredits = LedgerEntry::where('tenant_id', $this->tenant->id)
            ->where('account_id', $this->cropWipAccount->id)
            ->whereIn('posting_group_id', [$pg1->id, $pg2->id])
            ->sum('credit_amount');
        $this->assertEquals(100, (float) $totalWipCredits, 'Total WIP credits should be 100, not 200');

        // Verify inventory valuation: Harvest 1 gets cost, Harvest 2 gets 0 cost
        $balance1 = InvStockBalance::where('tenant_id', $this->tenant->id)
            ->where('store_id', $this->store->id)
            ->where('item_id', $this->item->id)
            ->first();
        $this->assertEquals(10, (float) $balance1->qty_on_hand, 'Total quantity should be 10');
        $this->assertEquals(100, (float) $balance1->value_on_hand, 'Total value should be 100 (from Harvest 1 only)');
    }

    public function test_allocation_rows_contain_harvest_line_id(): void
    {
        $this->createWipCost(200);

        $harvest = Harvest::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'project_id' => $this->project->id,
            'harvest_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);

        $line1 = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $this->item->id,
            'store_id' => $this->store->id,
            'quantity' => 10,
        ]);

        $item2 = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Rice',
            'uom_id' => $this->item->uom_id,
            'category_id' => $this->item->category_id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $line2 = HarvestLine::create([
            'tenant_id' => $this->tenant->id,
            'harvest_id' => $harvest->id,
            'inventory_item_id' => $item2->id,
            'store_id' => $this->store->id,
            'quantity' => 20,
        ]);

        $post = $this->withHeaders($this->headers())
            ->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
                'posting_date' => '2024-06-15',
            ]);

        $post->assertStatus(200);
        $harvest->refresh();

        $postingGroup = PostingGroup::find($harvest->posting_group_id);
        $allocationRows = AllocationRow::where('posting_group_id', $postingGroup->id)->get();

        $this->assertCount(2, $allocationRows);

        foreach ($allocationRows as $ar) {
            $snapshot = $ar->rule_snapshot;
            $this->assertIsArray($snapshot);
            $this->assertArrayHasKey('harvest_line_id', $snapshot, 'Allocation row should contain harvest_line_id in snapshot');
            $this->assertNotNull($snapshot['harvest_line_id']);
            $this->assertTrue(
                $snapshot['harvest_line_id'] === $line1->id || $snapshot['harvest_line_id'] === $line2->id,
                'harvest_line_id should match one of the harvest lines'
            );
        }
    }
}
