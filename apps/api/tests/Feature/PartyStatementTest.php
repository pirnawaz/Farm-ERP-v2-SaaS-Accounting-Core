<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Party;
use App\Models\ProjectRule;
use App\Models\OperationalTransaction;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Services\PartyFinancialSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class PartyStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(\App\Models\Tenant $tenant, array $keys): void
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

    public function test_party_statement_tenant_isolation(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        
        $party1 = Party::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Party 1',
            'party_types' => ['HARI'],
        ]);

        // Attempt to access from tenant2
        $response = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party1->id}/statement");

        $response->assertStatus(404);
    }

    public function test_party_statement_only_includes_posted_records(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_payments', 'treasury_advances']);
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
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Post settlement
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        // Create DRAFT payment (should not appear in statement)
        $draftPayment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 100.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        // Create and post payment
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 300.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        // Get statement
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/statement?from=2024-06-01&to=2024-06-30");

        $response->assertStatus(200);
        $data = $response->json();

        // Verify only posted payment appears
        $paymentLines = array_values(array_filter($data['line_items'], fn($line) => $line['type'] === 'PAYMENT'));
        $this->assertCount(1, $paymentLines);
        $this->assertEquals('300.00', $paymentLines[0]['amount']);
    }

    public function test_party_statement_grouping_by_cycle(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_advances']);
        $cropCycle1 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-30',
            'status' => 'OPEN',
        ]);
        $cropCycle2 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 2',
            'start_date' => '2024-07-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        // Create projects in different cycles
        $project1 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle1->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $project2 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $cropCycle2->id,
            'name' => 'Project 2',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project1->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
        ProjectRule::create([
            'project_id' => $project2->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        // Create and post transactions and settlements for both projects
        $income1 = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project1->id,
            'crop_cycle_id' => $cropCycle1->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income1->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project1->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        $income2 = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cropCycle2->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-08-15',
            'amount' => 2000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income2->id}/post", [
                'posting_date' => '2024-08-15',
                'idempotency_key' => 'income-2',
            ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project2->id}/settlement/post", [
                'posting_date' => '2024-08-15',
                'idempotency_key' => 'settlement-2',
            ]);

        // Get statement grouped by cycle
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/statement?from=2024-01-01&to=2024-12-31&group_by=cycle");

        $response->assertStatus(200);
        $data = $response->json();

        // Verify grouping by cycle
        $this->assertArrayHasKey('grouped_breakdown', $data);
        $this->assertGreaterThanOrEqual(2, count($data['grouped_breakdown']));
        
        // Verify each group has cycle info
        foreach ($data['grouped_breakdown'] as $group) {
            $this->assertArrayHasKey('crop_cycle_id', $group);
            $this->assertArrayHasKey('crop_cycle_name', $group);
            $this->assertArrayHasKey('projects', $group);
        }
    }

    public function test_party_statement_totals_match_balances(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_payments']);
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
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Post settlement
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        // Create and post payment
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 300.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        // Get balances (all time)
        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/balances");

        // Get statement (all time)
        $statementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/statement?from=2024-01-01&to=2024-12-31");

        $balancesResponse->assertStatus(200);
        $statementResponse->assertStatus(200);

        $balances = $balancesResponse->json();
        $statement = $statementResponse->json();

        // Verify totals match exactly (using same source)
        $this->assertEquals($balances['allocated_payable_total'], $statement['summary']['total_allocations_increasing_balance'], 'Allocated totals must match');
        $this->assertEquals($balances['paid_total'], $statement['summary']['total_payments_out'], 'Paid totals must match');
        $this->assertEquals($balances['outstanding_total'], $statement['summary']['closing_balance_payable'], 'Outstanding totals must match');
    }

    public function test_party_statement_date_range_consistency(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['settlements', 'treasury_payments']);
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
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'income-1',
            ]);

        // Post settlement
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        // Create and post payment
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 300.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        // Get balances for date range
        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/balances?as_of=2024-06-30");

        // Get statement for same date range
        $statementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/statement?from=2024-01-01&to=2024-06-30");

        $balancesResponse->assertStatus(200);
        $statementResponse->assertStatus(200);

        $balances = $balancesResponse->json();
        $statement = $statementResponse->json();

        // Verify closing balances from statement match balances endpoint
        // (Both should use same source data up to the date)
        $this->assertEquals($balances['allocated_payable_total'], $statement['summary']['total_allocations_increasing_balance'], 'Allocated totals must match for date range');
        $this->assertEquals($balances['paid_total'], $statement['summary']['total_payments_out'], 'Paid totals must match for date range');
        $this->assertEquals($balances['outstanding_total'], $statement['summary']['closing_balance_payable'], 'Outstanding totals must match for date range');
    }

    /**
     * Active posting group scope: reversed original is excluded, reversal posting group is included.
     * Reporting (getPostedAllocationTotals) must exclude allocations from reversed posting groups.
     */
    public function test_active_posting_group_excludes_reversed_and_includes_reversal(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        // Original posted posting group (will be reversed)
        $originalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-15',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $originalPg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 500.00,
        ]);

        // Reversal posting group (points to original)
        $reversalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-20',
            'reversal_of_posting_group_id' => $originalPg->id,
        ]);

        // scopeActive: original must be excluded (it has a reversal), reversal must be included (it is not reversed)
        $activeIds = PostingGroup::query()->active()->pluck('id')->toArray();
        $this->assertNotContains($originalPg->id, $activeIds, 'Reversed original posting group must be excluded from active scope');
        $this->assertContains($reversalPg->id, $activeIds, 'Reversal posting group (itself not reversed) must be included in active scope');

        // getPostedAllocationTotals must exclude the original's allocation (only allocation is on reversed PG)
        $service = app(PartyFinancialSourceService::class);
        $result = $service->getPostedAllocationTotals($party->id, $tenant->id, '2024-01-01', '2024-12-31');
        $this->assertSame(0.0, (float) $result['total'], 'Allocations from reversed posting group must be excluded from posted totals');
        $this->assertCount(0, $result['allocations'], 'Allocations list must be empty when only allocation was on reversed posting group');

        // Allocation on the reversal posting group IS included by active filtering (reversals are not excluded)
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $reversalPg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 100.00,
        ]);
        $resultWithReversalAllocation = $service->getPostedAllocationTotals($party->id, $tenant->id, '2024-01-01', '2024-12-31');
        $this->assertSame(100.0, (float) $resultWithReversalAllocation['total'], 'Allocation on reversal posting group must be included in posted totals');
        $this->assertCount(1, $resultWithReversalAllocation['allocations'], 'Allocations list must include the reversal posting group allocation');
        $this->assertTrue(
            $resultWithReversalAllocation['allocations']->contains('posting_group_id', $reversalPg->id),
            'Included allocation must be the one on the reversal posting group'
        );
    }
}
