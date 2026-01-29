<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Party;
use App\Models\ProjectRule;
use App\Models\OperationalTransaction;
use App\Models\Payment;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Models\Settlement;
use App\Models\Sale;
use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class PaymentTest extends TestCase
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

    public function test_payment_posting_creates_ledger_entries(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'settlements']);
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
                'idempotency_key' => 'income-ledger-test',
            ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-ledger-test',
            ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 500.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        $response->assertStatus(201);
        $data = $response->json();

        // Verify posting_group created
        $this->assertNotNull($data['id']);
        $this->assertEquals('ADJUSTMENT', $data['source_type']);
        $this->assertEquals($payment->id, $data['source_id']);

        // Verify payment status updated
        $payment->refresh();
        $this->assertEquals('POSTED', $payment->status);
        $this->assertNotNull($payment->posting_group_id);
        $this->assertNotNull($payment->posted_at);

        // Verify ledger entries
        $ledgerEntries = LedgerEntry::where('posting_group_id', $data['id'])->get();
        $this->assertCount(2, $ledgerEntries);

        $payableEntry = $ledgerEntries->firstWhere('debit_amount', '>', 0);
        $cashEntry = $ledgerEntries->firstWhere('credit_amount', '>', 0);

        $this->assertNotNull($payableEntry);
        $this->assertNotNull($cashEntry);
        $this->assertEquals('500.00', (string) $payableEntry->debit_amount);
        $this->assertEquals('500.00', (string) $cashEntry->credit_amount);
    }

    public function test_payment_idempotency(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'settlements']);
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
                'idempotency_key' => 'income-idem-test',
            ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-idem-test',
            ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 500.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $idempotencyKey = 'payment-key-123';

        // First post
        $response1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => $idempotencyKey,
                'crop_cycle_id' => $cropCycle->id,
            ]);

        $response1->assertStatus(201);
        $postingGroupId1 = $response1->json('id');

        // Second post with same key
        $response2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => $idempotencyKey,
                'crop_cycle_id' => $cropCycle->id,
            ]);

        $response2->assertStatus(201);
        $postingGroupId2 = $response2->json('id');

        // Should return the same posting_group
        $this->assertEquals($postingGroupId1, $postingGroupId2);

        // Verify only one posting_group exists
        $count = PostingGroup::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_payment_reduces_outstanding_payable(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'settlements', 'treasury_advances']);
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
        $settlementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        $settlementResponse->assertStatus(201);
        $settlement = Settlement::where('project_id', $project->id)->first();

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

        // Get balances
        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$hariParty->id}/balances");

        $balancesResponse->assertStatus(200);
        $balances = $balancesResponse->json();

        // Verify outstanding is reduced
        // Hari should have 500 allocated (50% of 1000), 300 paid, so 200 outstanding
        $this->assertEquals('500.00', $balances['allocated_payable_total']);
        $this->assertEquals('300.00', $balances['paid_total']);
        $this->assertEquals('200.00', $balances['outstanding_total']);
    }

    public function test_payment_tenant_isolation(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        $this->enableModules($tenant2, ['treasury_payments']);
        
        $cropCycle1 = CropCycle::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        
        $party1 = Party::create([
            'tenant_id' => $tenant1->id,
            'name' => 'Party 1',
            'party_types' => ['HARI'],
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant1->id,
            'party_id' => $party1->id,
            'direction' => 'OUT',
            'amount' => 500.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        // Attempt to access from tenant2
        $response = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/payments/{$payment->id}");

        $response->assertStatus(404);
    }

    public function test_payment_direction_in_creates_ar_entry(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'ar_sales']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        // Payment IN requires receivable balance: create and post a Sale first
        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 1500.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'sale-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 1000.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-in-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        $response->assertStatus(201);
        $data = $response->json();

        // Verify posting group created (Payment IN: source_type=ADJUSTMENT, source_id=payment)
        $this->assertNotNull($data['id']);
        $this->assertEquals('ADJUSTMENT', $data['source_type']);
        $this->assertEquals($payment->id, $data['source_id']);

        // Verify ledger entries: Dr CASH, Cr AR
        $ledgerEntries = LedgerEntry::where('posting_group_id', $data['id'])->get();
        $this->assertCount(2, $ledgerEntries);

        $cashEntry = $ledgerEntries->firstWhere('debit_amount', '>', 0);
        $arEntry = $ledgerEntries->firstWhere('credit_amount', '>', 0);

        $this->assertNotNull($cashEntry);
        $this->assertNotNull($arEntry);
        $this->assertEquals('1000.00', (string) $cashEntry->debit_amount);
        $this->assertEquals('1000.00', (string) $arEntry->credit_amount);
    }

    public function test_payment_linked_to_settlement(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'settlements', 'treasury_advances']);
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
        $settlementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'settlement-1',
            ]);

        $settlementResponse->assertStatus(201);
        $settlement = Settlement::where('project_id', $project->id)->first();

        // Create payment linked to settlement
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'amount' => 300.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'settlement_id' => $settlement->id,
            'status' => 'DRAFT',
        ]);

        // Post payment (should not require crop_cycle_id since it's linked to settlement)
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-1',
            ]);

        $response->assertStatus(201);
        $data = $response->json();

        // Verify posting_group has correct crop_cycle_id from settlement
        $this->assertEquals($cropCycle->id, $data['crop_cycle_id']);
    }
}
