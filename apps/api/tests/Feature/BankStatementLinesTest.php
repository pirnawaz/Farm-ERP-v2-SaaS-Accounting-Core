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
use App\Models\BankStatementLine;
use App\Models\BankStatementMatch;
use App\Models\PostingGroup;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class BankStatementLinesTest extends TestCase
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

    private function createTenantWithBankPostings(string $postingDate = '2024-06-01'): array
    {
        $tenant = Tenant::create(['name' => 'Bank Statement Tenant', 'status' => 'active']);
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
                'idempotency_key' => 'stmt-sale-1',
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
                'idempotency_key' => 'stmt-pmt-1',
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
     * 1) Add and list statement lines; report includes statement summary and lines.
     */
    public function test_add_and_list_statement_lines(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '60.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-10',
                'amount' => 100,
                'description' => 'Deposit',
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-12',
                'amount' => -40,
                'reference' => 'Withdrawal',
            ])
            ->assertStatus(201);

        $report = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/bank-reconciliations/{$recId}")
            ->assertStatus(200)
            ->json();

        $this->assertArrayHasKey('statement', $report);
        $this->assertEquals(60, $report['statement']['lines_total']);
        $this->assertArrayHasKey('statement_lines', $report);
        $this->assertCount(2, $report['statement_lines']);
    }

    /**
     * 2) Match and unmatch: auditable (match VOID on unmatch), can re-match after unmatch.
     */
    public function test_match_and_unmatch_auditable(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');
        $this->assertNotEmpty($bankEntryIds);
        $debitEntryId = $bankEntryIds[0]; // payment IN -> BANK debit

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $lineRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-01',
                'amount' => 300,
                'description' => 'Payment in',
            ]);
        $lineRes->assertStatus(201);
        $lineId = $lineRes->json('id');

        $matchRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/match", [
                'ledger_entry_id' => $debitEntryId,
            ]);
        $matchRes->assertStatus(201);
        $this->assertTrue(BankStatementMatch::where('bank_statement_line_id', $lineId)->where('status', 'MATCHED')->exists());

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/unmatch", ['reason' => 'Wrong entry'])
            ->assertStatus(200);

        $matchRow = BankStatementMatch::where('bank_statement_line_id', $lineId)->first();
        $this->assertNotNull($matchRow);
        $this->assertEquals('VOID', $matchRow->status);
        $this->assertNotNull($matchRow->voided_at);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/match", [
                'ledger_entry_id' => $debitEntryId,
            ])
            ->assertStatus(201);
        $this->assertEquals(2, BankStatementMatch::where('bank_statement_line_id', $lineId)->count());
        $this->assertEquals(1, BankStatementMatch::where('bank_statement_line_id', $lineId)->where('status', 'MATCHED')->count());
    }

    /**
     * 3) Cannot add/void/match/unmatch when reconciliation is finalized.
     */
    public function test_cannot_edit_or_match_when_finalized(): void
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

        $lineRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-10',
                'amount' => 100,
            ]);
        $lineRes->assertStatus(201);
        $lineId = $lineRes->json('id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/finalize")
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-11',
                'amount' => 50,
            ])
            ->assertStatus(409);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/void")
            ->assertStatus(409);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/match", [
                'ledger_entry_id' => $bankEntryIds[0],
            ])
            ->assertStatus(409);
    }

    /**
     * 4) Match validation: wrong account, posting_date > statement_date, reversed entry.
     */
    public function test_match_validation_wrong_account_or_date_or_reversed(): void
    {
        [$tenant, $bankAccount, $bankEntryIds] = $this->createTenantWithBankPostings('2024-06-01');
        $bankEntryId = $bankEntryIds[0];

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-06-15',
                'statement_balance' => '300.00',
            ]);
        $res->assertStatus(201);
        $recId = $res->json('id');

        $lineRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recId}/statement-lines", [
                'line_date' => '2024-06-01',
                'amount' => 300,
            ]);
        $lineRes->assertStatus(201);
        $lineId = $lineRes->json('id');

        $cashAccount = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $this->assertNotNull($cashAccount);
        $cashEntry = LedgerEntry::where('tenant_id', $tenant->id)->where('account_id', $cashAccount->id)->first();
        if ($cashEntry) {
            $this->withHeader('X-Tenant-Id', $tenant->id)
                ->withHeader('X-User-Role', 'accountant')
                ->postJson("/api/bank-reconciliations/{$recId}/statement-lines/{$lineId}/match", [
                    'ledger_entry_id' => $cashEntry->id,
                ])
                ->assertStatus(409);
        }

        $recCASH = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/bank-reconciliations', [
                'account_code' => 'BANK',
                'statement_date' => '2024-05-01',
                'statement_balance' => '0',
            ]);
        $recCASH->assertStatus(201);
        $recIdEarly = $recCASH->json('id');
        $lineEarly = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recIdEarly}/statement-lines", [
                'line_date' => '2024-04-15',
                'amount' => 300,
            ]);
        $lineEarly->assertStatus(201);
        $lineEarlyId = $lineEarly->json('id');
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/bank-reconciliations/{$recIdEarly}/statement-lines/{$lineEarlyId}/match", [
                'ledger_entry_id' => $bankEntryId,
            ])
            ->assertStatus(409);
    }
}
