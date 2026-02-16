<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\LedgerEntry;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationClear;
use App\Models\PostingGroup;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
        (new ModulesSeeder)->run();
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

    /**
     * Create tenant with BANK postings: one sale + one payment IN (method BANK).
     * Returns [tenant, bankAccount, ledgerEntryIdsForBank].
     */
    private function createTenantWithBankPostings(string $postingDate = '2024-06-01'): array
    {
        $tenant = Tenant::create(['name' => 'Bank Rec Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'treasury_payments']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 500.00,
            'posting_date' => $postingDate,
            'sale_date' => $postingDate,
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'bank-rec-sale-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 300.00,
            'payment_date' => $postingDate,
            'method' => 'BANK',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'bank-rec-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $bankAccount = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $this->assertNotNull($bankAccount);
        $bankEntryIds = LedgerEntry::where('tenant_id', $tenant->id)
            ->where('account_id', $bankAccount->id)
            ->pluck('id')
            ->all();

        return [$tenant, $bankAccount, $bankEntryIds];
    }

    /**
     * 1) Create reconciliation and compute book balance.
     */
    public function test_create_reconciliation_and_compute_book_balance(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
                'notes' => 'June statement',
            ]);
        $res->assertStatus(201);
        $rec = $res->json();
        $this->assertEquals('DRAFT', $rec['status']);
        $this->assertEquals($bankAccount->id, $rec['account_id']);

        $show = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/bank-reconciliations/' . $rec['id']);
        $show->assertStatus(200);
        $report = $show->json();
        $this->assertEquals(300, $report['book_balance'], 'Book balance should be SUM(debit-credit) for BANK = 300');
        $this->assertEquals(300, $report['statement_balance']);
        $this->assertEquals(0, $report['cleared_balance']);
        $this->assertEquals(1, $report['cleared_counts']['uncleared']);
    }

    /**
     * 2) Clear entries: cleared_balance changes, book_balance unchanged, ledger_entries count unchanged.
     */
    public function test_clear_entries_changes_cleared_balance_not_book_balance(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');
        $this->assertNotEmpty($bankEntryIds);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $ledgerCountBefore = LedgerEntry::where('tenant_id', $tenant->id)->count();

        $clearRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/clear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ]);
        $clearRes->assertStatus(201);

        $ledgerCountAfter = LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertEquals($ledgerCountBefore, $ledgerCountAfter, 'Ledger entries must not change');

        $show = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/bank-reconciliations/{$recId}");
        $show->assertStatus(200);
        $report = $show->json();
        $this->assertEquals(300, $report['book_balance'], 'Book balance unchanged');
        $this->assertEquals(300, $report['cleared_balance'], 'Cleared balance reflects cleared entry');
        $this->assertEquals(1, $report['cleared_counts']['cleared']);
        $this->assertEquals(0, $report['cleared_counts']['uncleared']);
    }

    /**
     * 3) Unclear: void clear (auditable); status VOID, voided_at set.
     */
    public function test_unclear_voids_clear_auditable(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/clear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ])
            ->assertStatus(201);

        $clearRow = BankReconciliationClear::where('bank_reconciliation_id', $recId)
            ->where('ledger_entry_id', $bankEntryIds[0])
            ->first();
        $this->assertNotNull($clearRow);
        $this->assertEquals(BankReconciliationClear::STATUS_CLEARED, $clearRow->status);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/unclear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
                'reason' => 'Wrong match',
            ])
            ->assertStatus(200);

        $clearRow->refresh();
        $this->assertEquals(BankReconciliationClear::STATUS_VOID, $clearRow->status);
        $this->assertNotNull($clearRow->voided_at);
    }

    /**
     * 4) Cannot clear entry after statement_date or from reversed posting group.
     */
    public function test_cannot_clear_after_statement_date_or_reversed(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-20');

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '0.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $clearAfter = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/clear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ]);
        $clearAfter->assertStatus(409);

        [$tenant2, , $bankEntryIds2] = $this->createTenantWithBankPostings('2024-06-01');
        $res2 = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-30',
                'statement_balance' => '300.00',
            ]);
        $res2->assertStatus(201);
        $recId2 = $res2->json('id');

        $entry = LedgerEntry::where('tenant_id', $tenant2->id)->whereIn('id', $bankEntryIds2)->first();
        $pg = PostingGroup::find($entry->posting_group_id);
        PostingGroup::create([
            'tenant_id' => $tenant2->id,
            'source_type' => 'REVERSAL',
            'source_id' => $pg->id,
            'posting_date' => '2024-06-30',
            'idempotency_key' => 'rev-' . $pg->id,
            'reversal_of_posting_group_id' => $pg->id,
        ]);

        $clearReversed = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId2}/clear", [
                'ledger_entry_ids' => [$entry->id],
            ]);
        $clearReversed->assertStatus(409);
    }

    /**
     * 5) Finalize locks reconciliation: clear/unclear return 422.
     */
    public function test_finalize_locks_reconciliation(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $finalize = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/finalize");
        $finalize->assertStatus(200);

        $clearAfterFinalize = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/clear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ]);
        $clearAfterFinalize->assertStatus(422);

        $rec = BankReconciliation::find($recId);
        $rec->update(['status' => BankReconciliation::STATUS_DRAFT]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/clear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ])
            ->assertStatus(201);

        $rec->update(['status' => BankReconciliation::STATUS_FINALIZED]);
        $unclearAfterFinalize = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/unclear", [
                'ledger_entry_ids' => [$bankEntryIds[0]],
            ]);
        $unclearAfterFinalize->assertStatus(422);
    }
}
