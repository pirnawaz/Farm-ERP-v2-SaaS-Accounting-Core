<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Financial Statements Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        return $tenant;
    }

    /**
     * Create posting_group + ledger_entries directly (read-only report tests must not depend on journal post/period locking).
     */
    private function postEntries(Tenant $tenant, string $postingDate, array $lines): void
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => null,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'test-' . \Illuminate\Support\Str::uuid(),
        ]);
        foreach ($lines as $line) {
            LedgerEntry::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'account_id' => $line['account_id'],
                'debit_amount' => $line['debit_amount'],
                'credit_amount' => $line['credit_amount'],
                'currency_code' => 'GBP',
            ]);
        }
    }

    /**
     * P&L for range: income 500, expense 200, net_profit 300.
     */
    public function test_profit_loss_for_range(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($revenue);
        $this->assertNotNull($expense);
        $this->assertNotNull($equity);

        // DR BANK 1000 / CR PROFIT_DISTRIBUTION 1000 (equity injection)
        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ]);
        // DR EXP_SHARED 200 / CR BANK 200
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $expense->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ]);
        // DR BANK 500 / CR PROJECT_REVENUE 500
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss?from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame('2024-01-01', $data['from']);
        $this->assertSame('2024-01-31', $data['to']);
        $incomeSection = collect($data['sections'])->firstWhere('key', 'income');
        $expenseSection = collect($data['sections'])->firstWhere('key', 'expenses');
        $this->assertNotNull($incomeSection);
        $this->assertNotNull($expenseSection);
        $this->assertEqualsWithDelta(500.0, (float) $incomeSection['total'], 0.01, 'Income total');
        $this->assertEqualsWithDelta(200.0, (float) $expenseSection['total'], 0.01, 'Expense total');
        $this->assertEqualsWithDelta(300.0, (float) $data['net_profit'], 0.01, 'Net profit');
    }

    /**
     * Balance sheet as_of: assets 1300, liabilities 0, equity 1300 (1000 + net profit 300), equation_diff 0.
     */
    public function test_balance_sheet_as_of_balances_equation(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ]);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $expense->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ]);
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame('2024-01-31', $data['meta']['as_of']);
        $this->assertEqualsWithDelta(1300.0, (float) $data['totals']['assets_total'], 0.01, 'Total assets (BANK 1000-200+500)');
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['liabilities_total'], 0.01, 'Total liabilities');
        $this->assertEqualsWithDelta(1300.0, (float) $data['totals']['equity_total'], 0.01, 'Total equity (1000 + net profit 300)');
        $this->assertTrue($data['totals']['balanced'], 'Accounting equation should balance');
    }

    /**
     * Compare period: compare_from/compare_to returns compare totals and deltas.
     */
    public function test_compare_period_outputs_deltas(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);
        $this->postEntries($tenant, '2024-02-15', [
            ['account_id' => $bank->id, 'debit_amount' => 300, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 300],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss?from=2024-02-01&to=2024-02-28&compare_from=2024-01-01&compare_to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('compare', $data);
        $compare = $data['compare'];
        $this->assertSame('2024-01-01', $compare['from']);
        $this->assertSame('2024-01-31', $compare['to']);
        $this->assertArrayHasKey('net_profit', $compare);
        $this->assertArrayHasKey('delta', $compare);
        // Current period (Feb) has 300 revenue, compare period (Jan) has 0 revenue → net_profit Feb=300, Jan=0, delta=300
        $this->assertEqualsWithDelta(300.0, (float) $data['net_profit'], 0.01);
    }

    /**
     * Other tenant postings are not included.
     */
    public function test_tenant_isolation(): void
    {
        $tenant1 = $this->tenantWithAccounts();
        $tenant2 = $this->tenantWithAccounts();
        $bank1 = Account::where('tenant_id', $tenant1->id)->where('code', 'BANK')->first();
        $revenue1 = Account::where('tenant_id', $tenant1->id)->where('code', 'PROJECT_REVENUE')->first();
        $equity1 = Account::where('tenant_id', $tenant1->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $bank2 = Account::where('tenant_id', $tenant2->id)->where('code', 'BANK')->first();
        $revenue2 = Account::where('tenant_id', $tenant2->id)->where('code', 'PROJECT_REVENUE')->first();
        $equity2 = Account::where('tenant_id', $tenant2->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant1, '2024-01-10', [
            ['account_id' => $bank1->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity1->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ]);
        $this->postEntries($tenant1, '2024-01-15', [
            ['account_id' => $bank1->id, 'debit_amount' => 50, 'credit_amount' => 0],
            ['account_id' => $revenue1->id, 'debit_amount' => 0, 'credit_amount' => 50],
        ]);
        $this->postEntries($tenant2, '2024-01-12', [
            ['account_id' => $bank2->id, 'debit_amount' => 9999, 'credit_amount' => 0],
            ['account_id' => $equity2->id, 'debit_amount' => 0, 'credit_amount' => 9999],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss?from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();
        $incomeSection = collect($data['sections'])->firstWhere('key', 'income');
        $this->assertEqualsWithDelta(50.0, (float) $incomeSection['total'], 0.01, 'Tenant1 income only');
        $this->assertEqualsWithDelta(50.0, (float) $data['net_profit'], 0.01);

        $response2 = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response2->assertStatus(200);
        $data2 = $response2->json();
        $this->assertEqualsWithDelta(150.0, (float) $data2['totals']['assets_total'], 0.01, 'Tenant1 assets 100+50 only');
    }
}
