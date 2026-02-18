<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class GeneralLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'GL Report Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        return $tenant;
    }

    private function postEntries(Tenant $tenant, string $postingDate, array $lines, ?string $cropCycleId = null, ?string $reversalOfPostingGroupId = null): PostingGroup
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'test-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => $reversalOfPostingGroupId,
        ]);
        foreach ($lines as $line) {
            LedgerEntry::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'account_id' => $line['account_id'],
                'debit_amount' => $line['debit_amount'],
                'credit_amount' => $line['credit_amount'],
                'currency_code' => $line['currency_code'] ?? 'GBP',
            ]);
        }
        return $pg;
    }

    /**
     * Running balance: opening_balance, entries order, running_balance progression, closing_balance.
     */
    public function test_running_balance_correctness(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($equity);

        // Before range: DR BANK 200 / CR EQUITY 200 → opening for BANK = 200
        $this->postEntries($tenant, '2024-01-05', [
            ['account_id' => $bank->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ]);
        // Within range: DR BANK 100 / CR EQUITY 100
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        // Within range: DR 0 / CR BANK 50 (credit to bank)
        $this->postEntries($tenant, '2024-01-25', [
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 50],
            ['account_id' => $equity->id, 'debit_amount' => 50, 'credit_amount' => 0],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?account_id=' . $bank->id . '&from=2024-01-10&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('meta', $data);
        $this->assertSame($tenant->id, $data['meta']['tenant_id']);
        $this->assertSame('2024-01-10', $data['meta']['from']);
        $this->assertSame('2024-01-31', $data['meta']['to']);
        $this->assertEqualsWithDelta(200.0, (float) $data['opening_balance'], 0.01, 'Opening = 200 from Jan 5');
        $this->assertArrayHasKey('entries', $data);
        $entries = $data['entries'];
        $this->assertCount(2, $entries, 'Two posting groups within range affecting BANK');
        $this->assertEqualsWithDelta(200.0 + 100.0, (float) $entries[0]['running_balance'], 0.01, 'After first entry: 200+100=300');
        $this->assertEqualsWithDelta(200.0 + 100.0 - 50.0, (float) $entries[1]['running_balance'], 0.01, 'After second: 250');
        $this->assertEqualsWithDelta(250.0, (float) $data['closing_balance'], 0.01, 'Closing = 250');
    }

    /**
     * As-of/range: post before from (opening), within range (entries), after to (excluded).
     */
    public function test_range_behavior_opening_within_after_excluded(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-01', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        $this->postEntries($tenant, '2024-02-10', [
            ['account_id' => $bank->id, 'debit_amount' => 999, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 999],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?account_id=' . $bank->id . '&from=2024-01-10&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(500.0, (float) $data['opening_balance'], 0.01, 'Only Jan 1 posting before from');
        $this->assertCount(1, $data['entries'], 'Only Jan 15 in range');
        $this->assertEqualsWithDelta(600.0, (float) $data['closing_balance'], 0.01, '500+100, Feb 10 excluded');
    }

    /**
     * Tenant isolation: two tenants with postings to same account code; request as tenant1.
     */
    public function test_tenant_isolation(): void
    {
        $tenant1 = $this->tenantWithAccounts();
        $tenant2 = $this->tenantWithAccounts();
        $bank1 = Account::where('tenant_id', $tenant1->id)->where('code', 'BANK')->first();
        $equity1 = Account::where('tenant_id', $tenant1->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $bank2 = Account::where('tenant_id', $tenant2->id)->where('code', 'BANK')->first();
        $equity2 = Account::where('tenant_id', $tenant2->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant1, '2024-01-15', [
            ['account_id' => $bank1->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity1->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        $this->postEntries($tenant2, '2024-01-15', [
            ['account_id' => $bank2->id, 'debit_amount' => 9999, 'credit_amount' => 0],
            ['account_id' => $equity2->id, 'debit_amount' => 0, 'credit_amount' => 9999],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?account_id=' . $bank1->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(0.0, (float) $data['opening_balance'], 0.01);
        $this->assertCount(1, $data['entries']);
        $this->assertEqualsWithDelta(100.0, (float) $data['entries'][0]['debit'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $data['closing_balance'], 0.01, 'Tenant1 only');
    }

    /**
     * Reversal: original PG + reversal PG (reversal_of_posting_group_id points to original).
     * Both excluded → report shows no effect from that pair.
     */
    public function test_reversal_pair_excluded_from_report(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        // Original: DR BANK 300 / CR EQUITY 300
        $originalPg = $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 300, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 300],
        ]);
        // Reversal: CR BANK 300 / DR EQUITY 300 (reverses original)
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 300],
            ['account_id' => $equity->id, 'debit_amount' => 300, 'credit_amount' => 0],
        ], null, $originalPg->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?account_id=' . $bank->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(0.0, (float) $data['opening_balance'], 0.01);
        $this->assertCount(0, $data['entries'], 'Both original and reversal excluded');
        $this->assertEqualsWithDelta(0.0, (float) $data['closing_balance'], 0.01, 'Net voided');
    }

    /**
     * Validation: missing account_id, from, or to returns 422.
     */
    public function test_validation_requires_account_id_from_to(): void
    {
        $tenant = $this->tenantWithAccounts();
        $url = '/api/reports/general-ledger';
        $base = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $r1 = $this->withHeaders($base)->getJson($url . '?from=2024-01-01&to=2024-01-31');
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['account_id']);

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $r2 = $this->withHeaders($base)->getJson($url . '?account_id=' . $bank->id . '&to=2024-01-31');
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['from']);

        $r3 = $this->withHeaders($base)->getJson($url . '?account_id=' . $bank->id . '&from=2024-01-01');
        $r3->assertStatus(422);
        $r3->assertJsonValidationErrors(['to']);
    }
}
