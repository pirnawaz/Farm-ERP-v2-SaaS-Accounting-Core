<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Project;
use App\Models\OperationalTransaction;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Account;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\PostingService;
use App\Services\ReversalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class PostingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Project $project;
    private CropCycle $cropCycle;
    private Account $cashAccount;
    private Account $projectRevenueAccount;
    private Account $expSharedAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $this->cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();
        $this->projectRevenueAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $this->expSharedAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->first();
    }

    private function createExpense(float $amount = 100.00, string $classification = 'SHARED'): OperationalTransaction
    {
        return OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => $amount,
            'classification' => $classification,
        ]);
    }

    private function createIncome(float $amount = 500.00): OperationalTransaction
    {
        return OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-02-01',
            'amount' => $amount,
            'classification' => 'SHARED',
        ]);
    }

    private function headers(): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function postService(): PostingService
    {
        return $this->app->make(PostingService::class);
    }

    public function test_can_post_expense_entry(): void
    {
        $entry = $this->createExpense(100.50);

        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-exp-1'
        );

        $this->assertNotNull($postingGroup);
        $this->assertEquals('OPERATIONAL', $postingGroup->source_type);
        $this->assertEquals($entry->id, $postingGroup->source_id);
        $this->assertEquals('2024-01-15', $postingGroup->posting_date->format('Y-m-d'));

        $entry->refresh();
        $this->assertEquals('POSTED', $entry->status);

        $allocationRow = AllocationRow::where('posting_group_id', $postingGroup->id)->first();
        $this->assertNotNull($allocationRow);
        $this->assertEquals('POOL_SHARE', $allocationRow->allocation_type);
        $this->assertEquals(100.50, (float) $allocationRow->amount);

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $this->assertCount(2, $ledgerEntries);

        $debitTotal = $ledgerEntries->sum('debit_amount');
        $creditTotal = $ledgerEntries->sum('credit_amount');
        $this->assertEquals($debitTotal, $creditTotal);
        $this->assertEquals(100.50, (float) $debitTotal);

        $expEntry = $ledgerEntries->firstWhere('account_id', $this->expSharedAccount->id);
        $this->assertNotNull($expEntry);
        $this->assertEquals(100.50, (float) $expEntry->debit_amount);
        $this->assertEquals(0, (float) $expEntry->credit_amount);

        $cashEntry = $ledgerEntries->firstWhere('account_id', $this->cashAccount->id);
        $this->assertNotNull($cashEntry);
        $this->assertEquals(0, (float) $cashEntry->debit_amount);
        $this->assertEquals(100.50, (float) $cashEntry->credit_amount);
    }

    public function test_can_post_income_entry(): void
    {
        $entry = $this->createIncome(500.00);

        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-02-01',
            'idem-inc-1'
        );

        $this->assertNotNull($postingGroup);

        $allocationRow = AllocationRow::where('posting_group_id', $postingGroup->id)->first();
        $this->assertEquals('POOL_SHARE', $allocationRow->allocation_type);

        $ledgerEntries = LedgerEntry::where('posting_group_id', $postingGroup->id)->get();
        $cashEntry = $ledgerEntries->firstWhere('account_id', $this->cashAccount->id);
        $this->assertEquals(500.00, (float) $cashEntry->debit_amount);
        $this->assertEquals(0, (float) $cashEntry->credit_amount);

        $incomeEntry = $ledgerEntries->firstWhere('account_id', $this->projectRevenueAccount->id);
        $this->assertEquals(0, (float) $incomeEntry->debit_amount);
        $this->assertEquals(500.00, (float) $incomeEntry->credit_amount);
    }

    public function test_posting_is_idempotent(): void
    {
        $entry = $this->createExpense(100.00);

        $postingGroup1 = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-same'
        );
        $postingGroup2 = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-same'
        );

        $this->assertEquals($postingGroup1->id, $postingGroup2->id);

        $count = PostingGroup::where('source_type', 'OPERATIONAL')
            ->where('source_id', $entry->id)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_cannot_post_to_closed_crop_cycle(): void
    {
        $this->cropCycle->update(['status' => 'CLOSED']);
        $entry = $this->createExpense(100.00);

        $this->expectException(\Exception::class);
        $this->postService()->postOperationalTransaction($entry->id, $this->tenant->id, '2024-01-15', 'idem-1');
    }

    public function test_cannot_post_with_date_outside_crop_cycle_range(): void
    {
        $entry = $this->createExpense(100.00);
        $svc = $this->postService();

        try {
            $svc->postOperationalTransaction($entry->id, $this->tenant->id, '2023-12-31', 'idem-before');
            $this->fail('Expected exception for date before cycle start');
        } catch (\Exception $e) {
            $this->assertStringContainsString('before', strtolower($e->getMessage()));
        }

        $entry2 = $this->createExpense(50.00);
        try {
            $svc->postOperationalTransaction($entry2->id, $this->tenant->id, '2025-01-01', 'idem-after');
            $this->fail('Expected exception for date after cycle end');
        } catch (\Exception $e) {
            $this->assertStringContainsString('after', strtolower($e->getMessage()));
        }
    }

    public function test_cannot_update_posted_entry(): void
    {
        $entry = $this->createExpense(100.00);
        $this->postService()->postOperationalTransaction($entry->id, $this->tenant->id, '2024-01-15', 'idem-1');

        $response = $this->withHeaders($this->headers())
            ->putJson("/api/operational-transactions/{$entry->id}", ['amount' => 200.00]);

        $response->assertStatus(404);
    }

    public function test_cannot_delete_posted_entry(): void
    {
        $entry = $this->createExpense(100.00);
        $this->postService()->postOperationalTransaction($entry->id, $this->tenant->id, '2024-01-15', 'idem-1');

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/operational-transactions/{$entry->id}");

        $response->assertStatus(404);
    }

    public function test_posting_api_endpoint_works(): void
    {
        $entry = $this->createExpense(100.00);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'api-idem-1',
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('OPERATIONAL', $data['source_type']);
    }

    public function test_posting_group_read_endpoints_work(): void
    {
        $entry = $this->createExpense(100.00);
        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/posting-groups/{$postingGroup->id}");
        $response->assertStatus(200);
        $this->assertEquals($postingGroup->id, $response->json('id'));

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/posting-groups/{$postingGroup->id}/ledger-entries");
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/posting-groups/{$postingGroup->id}/allocation-rows");
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_snapshot_immutability(): void
    {
        $entry = $this->createExpense(100.00);
        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $allocationRow = AllocationRow::where('posting_group_id', $postingGroup->id)->first();
        $originalSnapshot = $allocationRow->rule_snapshot;

        // Create and post another transaction (should not affect first allocation row)
        $entry2 = $this->createExpense(50.00);
        $this->postService()->postOperationalTransaction($entry2->id, $this->tenant->id, '2024-01-16', 'idem-2');

        $allocationRow->refresh();
        $this->assertEquals($originalSnapshot, $allocationRow->rule_snapshot);
    }

    public function test_reversal_correctness(): void
    {
        $entry = $this->createExpense(100.00);
        $postingService = $this->postService();
        $originalPostingGroup = $postingService->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $reversalService = $this->app->make(ReversalService::class);
        $reversalPostingGroup = $reversalService->reversePostingGroup(
            $originalPostingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Correction: incorrect amount'
        );

        $this->assertEquals('REVERSAL', $reversalPostingGroup->source_type);
        $this->assertEquals($originalPostingGroup->id, $reversalPostingGroup->reversal_of_posting_group_id);
        $this->assertEquals('Correction: incorrect amount', $reversalPostingGroup->correction_reason);

        $originalEntries = LedgerEntry::where('posting_group_id', $originalPostingGroup->id)->get();
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPostingGroup->id)->get();

        $this->assertCount(2, $originalEntries);
        $this->assertCount(2, $reversalEntries);

        foreach ($originalEntries as $originalEntry) {
            $reversalEntry = $reversalEntries->firstWhere('account_id', $originalEntry->account_id);
            $this->assertNotNull($reversalEntry);
            $this->assertEquals((float) $originalEntry->debit_amount, (float) $reversalEntry->credit_amount);
            $this->assertEquals((float) $originalEntry->credit_amount, (float) $reversalEntry->debit_amount);
        }

        $reversalDebitTotal = $reversalEntries->sum('debit_amount');
        $reversalCreditTotal = $reversalEntries->sum('credit_amount');
        $this->assertEquals($reversalDebitTotal, $reversalCreditTotal);
        $this->assertEquals(100.00, (float) $reversalDebitTotal);
    }

    public function test_reversal_idempotency(): void
    {
        $entry = $this->createExpense(100.00);
        $originalPostingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $reversalService = $this->app->make(ReversalService::class);
        $reversal1 = $reversalService->reversePostingGroup(
            $originalPostingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Correction'
        );
        $reversal2 = $reversalService->reversePostingGroup(
            $originalPostingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Different reason'
        );

        $this->assertEquals($reversal1->id, $reversal2->id);

        $reversalCount = PostingGroup::where('reversal_of_posting_group_id', $originalPostingGroup->id)
            ->where('posting_date', '2024-01-20')
            ->count();
        $this->assertEquals(1, $reversalCount);
    }

    public function test_cannot_reverse_a_reversal(): void
    {
        $entry = $this->createExpense(100.00);
        $originalPostingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $reversalService = $this->app->make(ReversalService::class);
        $reversalPostingGroup = $reversalService->reversePostingGroup(
            $originalPostingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Correction'
        );

        $this->expectException(\InvalidArgumentException::class);
        $reversalService->reversePostingGroup(
            $reversalPostingGroup->id,
            $this->tenant->id,
            '2024-01-25',
            'Another correction'
        );
    }

    public function test_reversal_respects_closed_cycle_lock(): void
    {
        $entry = $this->createExpense(100.00);
        $originalPostingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $this->cropCycle->update(['status' => 'CLOSED']);

        $reversalService = $this->app->make(ReversalService::class);

        $this->expectException(\Exception::class);
        $reversalService->reversePostingGroup(
            $originalPostingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Correction'
        );
    }

    public function test_allocation_row_has_rule_snapshot(): void
    {
        $entry = $this->createExpense(100.00);
        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $allocationRow = AllocationRow::where('posting_group_id', $postingGroup->id)->first();
        $this->assertNotNull($allocationRow->rule_snapshot);
        $this->assertIsArray($allocationRow->rule_snapshot);
        $this->assertArrayHasKey('classification', $allocationRow->rule_snapshot);
        $this->assertEquals('SHARED', $allocationRow->rule_snapshot['classification']);
    }

    public function test_reversal_api_endpoint_works(): void
    {
        $entry = $this->createExpense(100.00);
        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/posting-groups/{$postingGroup->id}/reverse", [
                'posting_date' => '2024-01-20',
                'reason' => 'Correction needed',
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('REVERSAL', $data['source_type']);
        $this->assertEquals($postingGroup->id, $data['reversal_of_posting_group_id']);
    }

    public function test_reversals_list_endpoint_works(): void
    {
        $entry = $this->createExpense(100.00);
        $postingGroup = $this->postService()->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-1'
        );

        $reversalService = $this->app->make(ReversalService::class);
        $reversalService->reversePostingGroup(
            $postingGroup->id,
            $this->tenant->id,
            '2024-01-20',
            'Correction'
        );

        $response = $this->withHeaders($this->headers())
            ->getJson("/api/posting-groups/{$postingGroup->id}/reversals");

        $response->assertStatus(200);
        $reversals = $response->json();
        $this->assertCount(1, $reversals);
        $this->assertEquals('REVERSAL', $reversals[0]['source_type']);
    }
}
