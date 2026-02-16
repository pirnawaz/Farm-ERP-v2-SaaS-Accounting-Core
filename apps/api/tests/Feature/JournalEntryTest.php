<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Journal Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        return $tenant;
    }

    /**
     * Post journal creates posting_group and balanced ledger_entries.
     */
    public function test_post_journal_creates_posting_group_and_balanced_ledger_entries(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();
        $this->assertNotNull($cash);
        $this->assertNotNull($expense);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'memo' => 'Test expense',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0, 'description' => 'Expense'],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 100, 'description' => 'Cash'],
                ],
            ]);
        $create->assertStatus(201);
        $journal = $create->json();
        $this->assertEquals('DRAFT', $journal['status']);
        $journalId = $journal['id'];

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post");
        $post->assertStatus(200);

        $journal = JournalEntry::where('id', $journalId)->where('tenant_id', $tenant->id)->first();
        $this->assertEquals('POSTED', $journal->status);
        $this->assertNotNull($journal->posting_group_id);

        $pg = PostingGroup::where('id', $journal->posting_group_id)->first();
        $this->assertNotNull($pg);
        $this->assertEquals('JOURNAL_ENTRY', $pg->source_type);
        $this->assertEquals($journalId, $pg->source_id);

        $entries = LedgerEntry::where('posting_group_id', $pg->id)->get();
        $this->assertCount(2, $entries);
        $debits = $entries->sum('debit_amount');
        $credits = $entries->sum('credit_amount');
        $this->assertEquals(100, (float) $debits);
        $this->assertEquals(100, (float) $credits);
    }

    /**
     * Cannot post unbalanced journal.
     */
    public function test_cannot_post_unbalanced_journal(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 90],
                ],
            ]);
        $create->assertStatus(201);
        $journalId = $create->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post");
        $post->assertStatus(422);
        $this->assertStringContainsString('balanced', strtolower($post->json('message') ?? ''));
    }

    /**
     * Reverse journal creates reversal posting_group and marks journal REVERSED.
     */
    public function test_reverse_journal_creates_reversal_posting_group_and_marks_reversed(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $create->assertStatus(201);
        $journalId = $create->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post")
            ->assertStatus(200);

        $reverse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/reverse", ['memo' => 'Reversal test']);
        $reverse->assertStatus(200);

        $journal = JournalEntry::where('id', $journalId)->where('tenant_id', $tenant->id)->first();
        $this->assertEquals('REVERSED', $journal->status);
        $this->assertNotNull($journal->reversal_posting_group_id);
        $this->assertNotNull($journal->reversed_at);

        $reversalPg = PostingGroup::where('id', $journal->reversal_posting_group_id)->first();
        $this->assertNotNull($reversalPg);
        $this->assertEquals('REVERSAL', $reversalPg->source_type);
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPg->id)->get();
        $this->assertCount(2, $reversalEntries);
        // Reversal: original debit becomes credit and vice versa
        foreach ($reversalEntries as $e) {
            if ($e->account_id === $expense->id) {
                $this->assertEquals(0, (float) $e->debit_amount);
                $this->assertEquals(100, (float) $e->credit_amount);
            } else {
                $this->assertEquals(100, (float) $e->debit_amount);
                $this->assertEquals(0, (float) $e->credit_amount);
            }
        }
    }

    /**
     * Draft can be edited; POSTED/REVERSED cannot (409).
     */
    public function test_draft_can_be_edited_but_posted_cannot(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'memo' => 'Original',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 50, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 50],
                ],
            ]);
        $create->assertStatus(201);
        $journalId = $create->json('id');

        $putDraft = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/journals/{$journalId}", [
                'entry_date' => '2024-06-16',
                'memo' => 'Updated memo',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 60, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 60],
                ],
            ]);
        $putDraft->assertStatus(200);
        $this->assertEquals('Updated memo', $putDraft->json('memo'));

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/journals/{$journalId}/post")
            ->assertStatus(200);

        $putPosted = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/journals/{$journalId}", [
                'entry_date' => '2024-06-17',
                'memo' => 'Should fail',
                'lines' => [
                    ['account_id' => $expense->id, 'debit_amount' => 1, 'credit_amount' => 0],
                    ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 1],
                ],
            ]);
        $putPosted->assertStatus(409);
    }

    /**
     * Tenant isolation: cannot reference account from another tenant; listing only returns tenant journals.
     */
    public function test_tenant_isolation_accounts_and_journals(): void
    {
        $tenantA = $this->tenantWithAccounts();
        $tenantB = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenantB->id);

        $cashA = Account::where('tenant_id', $tenantA->id)->where('code', 'CASH')->first();
        $cashB = Account::where('tenant_id', $tenantB->id)->where('code', 'CASH')->first();

        // Create journal on tenant A with tenant A accounts
        $createA = $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'lines' => [
                    ['account_id' => $cashA->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => Account::where('tenant_id', $tenantA->id)->where('code', 'BANK')->first()->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $createA->assertStatus(201);
        $journalAId = $createA->json('id');

        // Try to create journal on tenant B but with tenant A's account (will fail in service when validating account belongs to tenant)
        $expenseB = Account::where('tenant_id', $tenantB->id)->where('code', 'INPUTS_EXPENSE')->first();
        $createCross = $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/journals', [
                'entry_date' => '2024-06-15',
                'lines' => [
                    ['account_id' => $cashA->id, 'debit_amount' => 100, 'credit_amount' => 0],
                    ['account_id' => $expenseB->id, 'debit_amount' => 0, 'credit_amount' => 100],
                ],
            ]);
        $createCross->assertStatus(404); // Account from other tenant -> firstOrFail throws ModelNotFoundException

        // List as tenant B: should not see tenant A's journal
        $listB = $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/journals');
        $listB->assertStatus(200);
        $ids = collect($listB->json())->pluck('id')->all();
        $this->assertNotContains($journalAId, $ids);

        // List as tenant A: should see only tenant A's journal
        $listA = $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/journals');
        $listA->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($listA->json()));
        $this->assertContains($journalAId, collect($listA->json())->pluck('id')->all());
    }
}
