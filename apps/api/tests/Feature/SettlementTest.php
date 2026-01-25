<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Party;
use App\Models\ProjectRule;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\Settlement;
use App\Models\SettlementOffset;
use App\Models\Advance;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    public function test_settlement_math_correctness(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 10.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post transactions
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);

        $sharedExpense = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 2000.00,
            'classification' => 'SHARED',
        ]);

        $hariOnlyExpense = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 500.00,
            'classification' => 'HARI_ONLY',
        ]);

        // Post transactions
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$sharedExpense->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'expense-1',
            ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$hariOnlyExpense->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'expense-2',
            ]);

        // Preview settlement
        $previewResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/preview", [
                'up_to_date' => '2024-06-15',
            ]);

        $previewResponse->assertStatus(200);
        $preview = $previewResponse->json();

        // Verify calculations per Decision D
        // pool_revenue = 10000
        // shared_costs = 2000
        // pool_profit = 10000 - 2000 = 8000
        // kamdari_amount = 8000 * 10/100 = 800
        // remaining_pool = 8000 - 800 = 7200
        // landlord_gross = 7200 * 50/100 = 3600
        // hari_gross = 7200 * 50/100 = 3600
        // hari_only_deductions = 500
        // hari_net = 3600 - 500 = 3100

        $this->assertEquals('10000.00', $preview['pool_revenue']);
        $this->assertEquals('2000.00', $preview['shared_costs']);
        $this->assertEquals('8000.00', $preview['pool_profit']);
        $this->assertEquals('800.00', $preview['kamdari_amount']);
        $this->assertEquals('3600.00', $preview['landlord_gross']);
        $this->assertEquals('3600.00', $preview['hari_gross']);
        $this->assertEquals('500.00', $preview['hari_only_deductions']);
        $this->assertEquals('3100.00', $preview['hari_net']);
    }

    public function test_settlement_idempotency(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Post at least one income so settlement creates non-zero ledger entries (avoids ledger_entries_debit_credit_required)
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'idempotency-income-1',
            ]);

        $idempotencyKey = 'settlement-key-123';

        // First settlement post
        $response1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $idempotencyKey,
            ]);

        $response1->assertStatus(201);
        $settlementId1 = $response1->json('settlement.id');

        // Second settlement post with same key
        $response2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $idempotencyKey,
            ]);

        $response2->assertStatus(201);
        $settlementId2 = $response2->json('settlement.id');

        // Should return the same settlement
        $this->assertEquals($settlementId1, $settlementId2);

        // Verify only one settlement exists
        $count = Settlement::where('project_id', $project->id)
            ->whereHas('postingGroup', function ($q) use ($tenant, $idempotencyKey) {
                $q->where('tenant_id', $tenant->id)
                  ->where('idempotency_key', $idempotencyKey);
            })
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_can_post_settlement_without_offset(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post income transaction
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Post settlement without offset
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'settlement-no-offset',
            ]);

        $response->assertStatus(201);
        $settlementId = $response->json('settlement.id');

        // Verify no offset was created
        $offsetCount = SettlementOffset::where('settlement_id', $settlementId)->count();
        $this->assertEquals(0, $offsetCount);
    }

    public function test_can_post_settlement_with_offset(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post income transaction
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Create and post advance (OUT direction = Hari owes us); project_id required for allocation_rows
        $advance = Advance::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'project_id' => $project->id,
            'type' => 'HARI_ADVANCE',
            'direction' => 'OUT',
            'amount' => 2000.00,
            'posting_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
            'crop_cycle_id' => $cropCycle->id,
        ]);

        $advancePostRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/advances/{$advance->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'advance-1',
            ]);
        $advancePostRes->assertStatus(201);
        $advance->refresh();
        $this->assertEquals('POSTED', $advance->status);

        // Post settlement with offset
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'settlement-with-offset',
                'apply_advance_offset' => true,
                'advance_offset_amount' => 1500.00,
            ]);

        $response->assertStatus(201);
        $settlementId = $response->json('settlement.id');

        // Verify offset was created
        $offset = SettlementOffset::where('settlement_id', $settlementId)->first();
        $this->assertNotNull($offset);
        $this->assertEquals(1500.00, (float) $offset->offset_amount);
        $this->assertEquals($hariParty->id, $offset->party_id);

        // Verify allocation rows for offset were created
        $offsetAllocations = AllocationRow::where('posting_group_id', $offset->posting_group_id)
            ->where('allocation_type', 'ADVANCE_OFFSET')
            ->get();
        $this->assertEquals(2, $offsetAllocations->count()); // Two rows: reduce payable and reduce advance

        // Verify ledger entries: Debit PAYABLE_HARI and Credit ADVANCE_HARI
        $postingGroup = PostingGroup::where('id', $offset->posting_group_id)->first();
        $payableHariDebit = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->whereHas('account', function ($q) use ($tenant) {
                $q->where('code', 'PAYABLE_HARI')->where('tenant_id', $tenant->id);
            })
            ->where('debit_amount', '>', 0)
            ->first();
        $this->assertNotNull($payableHariDebit);
        $this->assertEquals(1500.00, (float) $payableHariDebit->debit_amount);

        $advanceHariCredit = LedgerEntry::where('posting_group_id', $postingGroup->id)
            ->whereHas('account', function ($q) use ($tenant) {
                $q->where('code', 'ADVANCE_HARI')->where('tenant_id', $tenant->id);
            })
            ->where('credit_amount', '>', 0)
            ->first();
        $this->assertNotNull($advanceHariCredit);
        $this->assertEquals(1500.00, (float) $advanceHariCredit->credit_amount);
    }

    public function test_offset_cannot_exceed_outstanding_advance(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post income transaction
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Create advance of only 1000; project_id required for allocation_rows
        $advance = Advance::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'project_id' => $project->id,
            'type' => 'HARI_ADVANCE',
            'direction' => 'OUT',
            'amount' => 1000.00,
            'posting_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
            'crop_cycle_id' => $cropCycle->id,
        ]);

        $advancePostRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/advances/{$advance->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'advance-1',
            ]);
        $advancePostRes->assertStatus(201);

        // Try to post settlement with offset exceeding outstanding advance
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'settlement-invalid-offset',
                'apply_advance_offset' => true,
                'advance_offset_amount' => 2000.00, // Exceeds 1000 outstanding
            ]);

        $response->assertStatus(500); // Should fail validation
        $this->assertStringContainsString('Advance offset amount', $response->getContent());
    }

    public function test_offset_cannot_exceed_hari_payable(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create small income (hari_net will be small)
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Create large advance; project_id required for allocation_rows
        $advance = Advance::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'project_id' => $project->id,
            'type' => 'HARI_ADVANCE',
            'direction' => 'OUT',
            'amount' => 10000.00,
            'posting_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
            'crop_cycle_id' => $cropCycle->id,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/advances/{$advance->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'advance-1',
            ]);

        // Try to offset more than hari payable (hari_net will be ~500, but trying to offset 2000)
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'settlement-invalid-offset-2',
                'apply_advance_offset' => true,
                'advance_offset_amount' => 2000.00, // Exceeds hari payable
            ]);

        $response->assertStatus(500); // Should fail validation
    }

    public function test_offset_preview_endpoint(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post income
        $income = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 10000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Create advance; project_id required for allocation_rows
        $advance = Advance::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'project_id' => $project->id,
            'type' => 'HARI_ADVANCE',
            'direction' => 'OUT',
            'amount' => 2000.00,
            'posting_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
            'crop_cycle_id' => $cropCycle->id,
        ]);

        $advancePostRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/advances/{$advance->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'advance-1',
            ]);
        $advancePostRes->assertStatus(201);

        // Get offset preview
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/projects/{$project->id}/settlement/offset-preview?posting_date=2024-06-20");

        $response->assertStatus(200);
        $preview = $response->json();

        $this->assertEquals($hariParty->id, $preview['hari_party_id']);
        $this->assertGreaterThan(0, $preview['hari_payable_amount']);
        $this->assertEquals(2000.00, $preview['outstanding_advance']);
        $this->assertLessThanOrEqual($preview['hari_payable_amount'], $preview['suggested_offset']);
        $this->assertLessThanOrEqual($preview['outstanding_advance'], $preview['suggested_offset']);
    }
}
