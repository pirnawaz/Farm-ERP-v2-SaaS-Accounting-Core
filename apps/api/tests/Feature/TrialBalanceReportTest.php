<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class TrialBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Trial Balance Tenant', 'status' => 'active']);
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
     * Trial balance returns rows grouped by account, correct aggregation and balanced totals.
     */
    public function test_trial_balance_aggregation_and_balanced_totals(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($revenue);
        $this->assertNotNull($equity);

        // DR BANK 1000 / CR EQUITY 1000
        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ]);
        // DR BANK 500 / CR REVENUE 500
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame('2024-01-31', $data['as_of']);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('balanced', $data);

        $rows = $data['rows'];
        $this->assertGreaterThanOrEqual(3, count($rows), 'At least BANK, PROJECT_REVENUE, PROFIT_DISTRIBUTION');

        $byCode = collect($rows)->keyBy('account_code');
        $this->assertArrayHasKey('BANK', $byCode);
        $this->assertArrayHasKey('PROJECT_REVENUE', $byCode);
        $this->assertArrayHasKey('PROFIT_DISTRIBUTION', $byCode);

        $this->assertEqualsWithDelta(1500.0, (float) $byCode['BANK']['total_debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $byCode['BANK']['total_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $byCode['PROJECT_REVENUE']['total_debit'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $byCode['PROJECT_REVENUE']['total_credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $byCode['PROFIT_DISTRIBUTION']['total_debit'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $byCode['PROFIT_DISTRIBUTION']['total_credit'], 0.01);

        $totals = $data['totals'];
        $totalDebit = (float) $totals['total_debit'];
        $totalCredit = (float) $totals['total_credit'];
        $this->assertEqualsWithDelta(1500.0, $totalDebit, 0.01, 'Total debits');
        $this->assertEqualsWithDelta(1500.0, $totalCredit, 0.01, 'Total credits');
        $this->assertTrue($data['balanced'], 'Trial balance should be balanced (debits = credits)');
    }

    /**
     * Tenant isolation: only current tenant's data is returned.
     */
    public function test_trial_balance_tenant_isolation(): void
    {
        $tenant1 = $this->tenantWithAccounts();
        $tenant2 = $this->tenantWithAccounts();
        $bank1 = Account::where('tenant_id', $tenant1->id)->where('code', 'BANK')->first();
        $equity1 = Account::where('tenant_id', $tenant1->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $bank2 = Account::where('tenant_id', $tenant2->id)->where('code', 'BANK')->first();
        $equity2 = Account::where('tenant_id', $tenant2->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant1, '2024-01-10', [
            ['account_id' => $bank1->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity1->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        $this->postEntries($tenant2, '2024-01-10', [
            ['account_id' => $bank2->id, 'debit_amount' => 9999, 'credit_amount' => 0],
            ['account_id' => $equity2->id, 'debit_amount' => 0, 'credit_amount' => 9999],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $totals = $data['totals'];
        $this->assertEqualsWithDelta(100.0, (float) $totals['total_debit'], 0.01, 'Tenant1 debits only');
        $this->assertEqualsWithDelta(100.0, (float) $totals['total_credit'], 0.01, 'Tenant1 credits only');
        $this->assertTrue($data['balanced']);
    }

    /**
     * As-of date excludes postings after that date.
     */
    public function test_trial_balance_as_of_excludes_future_postings(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        $this->postEntries($tenant, '2024-02-15', [
            ['account_id' => $bank->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $totals = $data['totals'];
        $this->assertEqualsWithDelta(100.0, (float) $totals['total_debit'], 0.01, 'Only Jan postings');
        $this->assertEqualsWithDelta(100.0, (float) $totals['total_credit'], 0.01);
        $this->assertTrue($data['balanced']);

        $response2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?as_of=2024-02-28');
        $response2->assertStatus(200);
        $data2 = $response2->json();
        $this->assertEqualsWithDelta(300.0, (float) $data2['totals']['total_debit'], 0.01, 'Jan + Feb postings');
        $this->assertEqualsWithDelta(300.0, (float) $data2['totals']['total_credit'], 0.01);
    }

    /**
     * Validation: as_of is required.
     */
    public function test_trial_balance_requires_as_of(): void
    {
        $tenant = $this->tenantWithAccounts();
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance');
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['as_of']);
    }

    /**
     * Reversal pair excluded (shared filter): original + reversal → net zero in trial balance.
     */
    public function test_trial_balance_excludes_reversal_pair(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $original = $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 500],
            ['account_id' => $equity->id, 'debit_amount' => 500, 'credit_amount' => 0],
        ], null, $original->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['total_debit'], 0.01, 'Reversal pair excluded');
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['total_credit'], 0.01);
        $this->assertTrue($data['balanced']);
    }
}
