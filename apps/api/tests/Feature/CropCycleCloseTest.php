<?php

namespace Tests\Feature;

use App\Domains\Accounting\PeriodClose\PeriodCloseService;
use App\Exceptions\CropCycleClosedException;
use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PeriodCloseRun;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PostingService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CropCycleCloseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;
    private Party $party;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\TenantContext::clear();

        $this->tenant = Tenant::create(['name' => 'Close Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->party->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'CLOSED',
        ]);

        $this->tenantAdmin = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Admin',
            'email' => 'admin-close@test.' . $this->tenant->id,
            'password' => Hash::make('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
    }

    /**
     * Create income/expense postings in the cycle to produce a given net profit.
     * Uses JOURNAL_ENTRY-style posting groups with PROJECT_REVENUE and EXP_SHARED.
     */
    private function postProfitLossEntries(float $incomeAmount, float $expenseAmount): void
    {
        $revenue = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
        $expense = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $bank = Account::where('tenant_id', $this->tenant->id)->where('code', 'BANK')->firstOrFail();

        if ($incomeAmount > 0) {
            $pg = PostingGroup::create([
                'tenant_id' => $this->tenant->id,
                'crop_cycle_id' => $this->cropCycle->id,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => (string) \Illuminate\Support\Str::uuid(),
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'close-test-income-' . \Illuminate\Support\Str::uuid(),
            ]);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => $incomeAmount, 'credit_amount' => 0, 'currency_code' => 'GBP']);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => $incomeAmount, 'currency_code' => 'GBP']);
            AllocationRow::create([
                'tenant_id' => $this->tenant->id,
                'posting_group_id' => $pg->id,
                'project_id' => $this->project->id,
                'party_id' => $this->party->id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => $incomeAmount,
            ]);
        }
        if ($expenseAmount > 0) {
            $pg = PostingGroup::create([
                'tenant_id' => $this->tenant->id,
                'crop_cycle_id' => $this->cropCycle->id,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => (string) \Illuminate\Support\Str::uuid(),
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'close-test-expense-' . \Illuminate\Support\Str::uuid(),
            ]);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $expense->id, 'debit_amount' => $expenseAmount, 'credit_amount' => 0, 'currency_code' => 'GBP']);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => $expenseAmount, 'currency_code' => 'GBP']);
            AllocationRow::create([
                'tenant_id' => $this->tenant->id,
                'posting_group_id' => $pg->id,
                'project_id' => $this->project->id,
                'party_id' => $this->party->id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => $expenseAmount,
            ]);
        }
    }

    /** @test */
    public function close_creates_closing_posting_group_and_locks_crop_cycle(): void
    {
        $this->postProfitLossEntries(500, 200);

        $service = $this->app->make(PeriodCloseService::class);
        $result = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $this->cropCycle->refresh();
        $this->assertSame('CLOSED', $this->cropCycle->status);

        $run = PeriodCloseRun::where('tenant_id', $this->tenant->id)->where('crop_cycle_id', $this->cropCycle->id)->firstOrFail();
        $this->assertSame('300.00', (string) $run->net_profit);

        $pg = PostingGroup::where('id', $result['posting_group_id'])->firstOrFail();
        $this->assertSame('PERIOD_CLOSE', $pg->source_type);
        $this->assertSame($run->id, $pg->source_id);
        $this->assertSame('2024-12-31', $pg->posting_date->format('Y-m-d'));

        $entries = LedgerEntry::where('posting_group_id', $pg->id)->get();
        $totalDebit = (float) $entries->sum('debit_amount');
        $totalCredit = (float) $entries->sum('credit_amount');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01, 'Posting group must balance');

        $revenue = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
        $expense = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $retained = Account::where('tenant_id', $this->tenant->id)->where('code', 'RETAINED_EARNINGS')->firstOrFail();
        $current = Account::where('tenant_id', $this->tenant->id)->where('code', 'CURRENT_EARNINGS')->firstOrFail();

        $revenueEntries = $entries->where('account_id', $revenue->id);
        $expenseEntries = $entries->where('account_id', $expense->id);
        $currentEntries = $entries->where('account_id', $current->id);
        $retainedEntries = $entries->where('account_id', $retained->id);

        $this->assertCount(1, $revenueEntries, 'Income account must have one closing line');
        $this->assertEquals(500, (float) $revenueEntries->first()->debit_amount);
        $this->assertEquals(0, (float) $revenueEntries->first()->credit_amount);

        $this->assertCount(1, $expenseEntries, 'Expense account must have one closing line');
        $this->assertEquals(0, (float) $expenseEntries->first()->debit_amount);
        $this->assertEquals(200, (float) $expenseEntries->first()->credit_amount);

        $currentNet = $currentEntries->sum('credit_amount') - $currentEntries->sum('debit_amount');
        $this->assertEqualsWithDelta(0, (float) $currentNet, 0.01, 'CURRENT_EARNINGS must net to zero');

        $retainedNetCredit = (float) $retainedEntries->sum('credit_amount') - (float) $retainedEntries->sum('debit_amount');
        $this->assertEqualsWithDelta(300, $retainedNetCredit, 0.01, 'RETAINED_EARNINGS net credit must equal net profit');
    }

    /** @test */
    public function full_closing_entries_zero_income_and_expense(): void
    {
        $this->postProfitLossEntries(500, 200);

        $service = $this->app->make(PeriodCloseService::class);
        $result = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $run = PeriodCloseRun::where('tenant_id', $this->tenant->id)->where('crop_cycle_id', $this->cropCycle->id)->firstOrFail();
        $this->assertSame('300.00', (string) $run->net_profit);

        $pg = PostingGroup::where('id', $result['posting_group_id'])->firstOrFail();
        $entries = LedgerEntry::where('posting_group_id', $pg->id)->get();

        $revenue = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
        $expense = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $current = Account::where('tenant_id', $this->tenant->id)->where('code', 'CURRENT_EARNINGS')->firstOrFail();
        $retained = Account::where('tenant_id', $this->tenant->id)->where('code', 'RETAINED_EARNINGS')->firstOrFail();

        $revenueLine = $entries->firstWhere('account_id', $revenue->id);
        $this->assertNotNull($revenueLine);
        $this->assertEqualsWithDelta(500, (float) $revenueLine->debit_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $revenueLine->credit_amount, 0.01);

        $expenseLine = $entries->firstWhere('account_id', $expense->id);
        $this->assertNotNull($expenseLine);
        $this->assertEqualsWithDelta(0, (float) $expenseLine->debit_amount, 0.01);
        $this->assertEqualsWithDelta(200, (float) $expenseLine->credit_amount, 0.01);

        $currentDebit = (float) $entries->where('account_id', $current->id)->sum('debit_amount');
        $currentCredit = (float) $entries->where('account_id', $current->id)->sum('credit_amount');
        $this->assertEqualsWithDelta($currentDebit, $currentCredit, 0.01);

        $retainedCredit = (float) $entries->where('account_id', $retained->id)->sum('credit_amount');
        $this->assertEqualsWithDelta(300, $retainedCredit, 0.01);

        $this->assertEqualsWithDelta($entries->sum('debit_amount'), $entries->sum('credit_amount'), 0.01);
    }

    /** @test */
    public function multiple_income_and_expense_accounts_each_zeroed_correctly(): void
    {
        $revenue1 = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
        $revenue2 = Account::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'OTHER_INCOME',
            'name' => 'Other Income',
            'type' => 'income',
            'is_system' => false,
        ]);
        $exp1 = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $exp2 = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_HARI_ONLY')->firstOrFail();
        $bank = Account::where('tenant_id', $this->tenant->id)->where('code', 'BANK')->firstOrFail();

        foreach ([[$revenue1, 300], [$revenue2, 200]] as [$acc, $amt]) {
            $pg = PostingGroup::create([
                'tenant_id' => $this->tenant->id,
                'crop_cycle_id' => $this->cropCycle->id,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => (string) \Illuminate\Support\Str::uuid(),
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'multi-' . $acc->id . '-' . \Illuminate\Support\Str::uuid(),
            ]);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => $amt, 'credit_amount' => 0, 'currency_code' => 'GBP']);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $acc->id, 'debit_amount' => 0, 'credit_amount' => $amt, 'currency_code' => 'GBP']);
            AllocationRow::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'project_id' => $this->project->id, 'party_id' => $this->party->id, 'allocation_type' => 'POOL_SHARE', 'amount' => $amt]);
        }
        foreach ([[$exp1, 100], [$exp2, 150]] as [$acc, $amt]) {
            $pg = PostingGroup::create([
                'tenant_id' => $this->tenant->id,
                'crop_cycle_id' => $this->cropCycle->id,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => (string) \Illuminate\Support\Str::uuid(),
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'multi-exp-' . $acc->id . '-' . \Illuminate\Support\Str::uuid(),
            ]);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $acc->id, 'debit_amount' => $amt, 'credit_amount' => 0, 'currency_code' => 'GBP']);
            LedgerEntry::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => $amt, 'currency_code' => 'GBP']);
            AllocationRow::create(['tenant_id' => $this->tenant->id, 'posting_group_id' => $pg->id, 'project_id' => $this->project->id, 'party_id' => $this->party->id, 'allocation_type' => 'POOL_SHARE', 'amount' => $amt]);
        }

        $service = $this->app->make(PeriodCloseService::class);
        $result = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $run = PeriodCloseRun::where('tenant_id', $this->tenant->id)->where('crop_cycle_id', $this->cropCycle->id)->firstOrFail();
        $this->assertEqualsWithDelta(250, (float) $run->net_profit, 0.01);

        $pg = PostingGroup::where('id', $result['posting_group_id'])->firstOrFail();
        $entries = LedgerEntry::where('posting_group_id', $pg->id)->get();

        foreach ([[$revenue1->id, 300], [$revenue2->id, 200]] as [$accountId, $expectedDebit]) {
            $line = $entries->firstWhere('account_id', $accountId);
            $this->assertNotNull($line, "Income account {$accountId} must have closing debit");
            $this->assertEqualsWithDelta($expectedDebit, (float) $line->debit_amount, 0.01);
            $this->assertEquals(0, (float) $line->credit_amount);
        }
        foreach ([[$exp1->id, 100], [$exp2->id, 150]] as [$accountId, $expectedCredit]) {
            $line = $entries->firstWhere('account_id', $accountId);
            $this->assertNotNull($line, "Expense account {$accountId} must have closing credit");
            $this->assertEquals(0, (float) $line->debit_amount);
            $this->assertEqualsWithDelta($expectedCredit, (float) $line->credit_amount, 0.01);
        }

        $current = Account::where('tenant_id', $this->tenant->id)->where('code', 'CURRENT_EARNINGS')->firstOrFail();
        $currentNet = (float) $entries->where('account_id', $current->id)->sum('credit_amount') - (float) $entries->where('account_id', $current->id)->sum('debit_amount');
        $this->assertEqualsWithDelta(0, $currentNet, 0.01);

        $this->assertEqualsWithDelta($entries->sum('debit_amount'), $entries->sum('credit_amount'), 0.01);

        $this->assertArrayHasKey('total_income', $run->snapshot_json);
        $this->assertArrayHasKey('total_expense', $run->snapshot_json);
        $this->assertArrayHasKey('net_profit', $run->snapshot_json);
        $this->assertArrayHasKey('accounts_closed', $run->snapshot_json);
        $this->assertEquals(1, $run->snapshot_json['accounts_closed']['income']);
        $this->assertEquals(1, $run->snapshot_json['accounts_closed']['expense']);

        $row = AllocationRow::where('posting_group_id', $pg->id)->firstOrFail();
        $this->assertArrayHasKey('count_income_accounts_closed', $row->rule_snapshot);
        $this->assertArrayHasKey('count_expense_accounts_closed', $row->rule_snapshot);
        $this->assertEquals(1, $row->rule_snapshot['count_income_accounts_closed']);
        $this->assertEquals(1, $row->rule_snapshot['count_expense_accounts_closed']);
    }

    /** @test */
    public function idempotency_second_close_returns_existing_run_and_creates_no_second_posting_group(): void
    {
        $this->postProfitLossEntries(100, 0);

        $service = $this->app->make(PeriodCloseService::class);
        $first = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);
        $second = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $this->assertSame($first['posting_group_id'], $second['posting_group_id']);
        $this->assertSame($first['net_profit'], $second['net_profit']);

        $count = PostingGroup::where('tenant_id', $this->tenant->id)->where('source_type', 'PERIOD_CLOSE')->where('crop_cycle_id', $this->cropCycle->id)->count();
        $this->assertSame(1, $count);
    }

    /** @test */
    public function lock_enforcement_after_close_posting_operational_transaction_fails(): void
    {
        $this->postProfitLossEntries(200, 50);

        $service = $this->app->make(PeriodCloseService::class);
        $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $this->project->update(['status' => 'ACTIVE']);

        $draftTxn = \App\Models\OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-07-01',
            'amount' => 10.00,
            'classification' => 'SHARED',
        ]);

        $this->expectException(CropCycleClosedException::class);
        $this->app->make(PostingService::class)->postOperationalTransaction(
            $draftTxn->id,
            $this->tenant->id,
            '2024-07-01',
            'idem-lock-test'
        );
    }

    /** @test */
    public function preconditions_fail_when_project_still_active(): void
    {
        $this->project->update(['status' => 'ACTIVE']);
        $this->postProfitLossEntries(100, 30);

        $service = $this->app->make(PeriodCloseService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ACTIVE');
        $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);
    }

    /** @test */
    public function tenant_isolation_other_tenant_cannot_close_or_fetch_run(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($otherTenant->id);

        $this->postProfitLossEntries(100, 20);
        $service = $this->app->make(PeriodCloseService::class);
        $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, null, null);

        $runForOther = $service->getCloseRun($otherTenant->id, $this->cropCycle->id);
        $this->assertNull($runForOther);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->closeCropCycle($otherTenant->id, $this->cropCycle->id, null, null);
    }

    /** @test */
    public function retained_earnings_direction_reversed_for_loss(): void
    {
        $this->postProfitLossEntries(100, 250);

        $service = $this->app->make(PeriodCloseService::class);
        $result = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $run = PeriodCloseRun::where('tenant_id', $this->tenant->id)->where('crop_cycle_id', $this->cropCycle->id)->firstOrFail();
        $this->assertSame('-150.00', (string) $run->net_profit);

        $pg = PostingGroup::where('id', $result['posting_group_id'])->firstOrFail();
        $entries = LedgerEntry::where('posting_group_id', $pg->id)->get();

        $revenue = Account::where('tenant_id', $this->tenant->id)->where('code', 'PROJECT_REVENUE')->firstOrFail();
        $expense = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $retained = Account::where('tenant_id', $this->tenant->id)->where('code', 'RETAINED_EARNINGS')->firstOrFail();
        $current = Account::where('tenant_id', $this->tenant->id)->where('code', 'CURRENT_EARNINGS')->firstOrFail();

        $revenueLine = $entries->firstWhere('account_id', $revenue->id);
        $this->assertNotNull($revenueLine);
        $this->assertEquals(100, (float) $revenueLine->debit_amount);
        $this->assertEquals(0, (float) $revenueLine->credit_amount);

        $expenseLine = $entries->firstWhere('account_id', $expense->id);
        $this->assertNotNull($expenseLine);
        $this->assertEquals(0, (float) $expenseLine->debit_amount);
        $this->assertEquals(250, (float) $expenseLine->credit_amount);

        $retainedNetDebit = (float) $entries->where('account_id', $retained->id)->sum('debit_amount') - (float) $entries->where('account_id', $retained->id)->sum('credit_amount');
        $this->assertEqualsWithDelta(150, $retainedNetDebit, 0.01, 'RETAINED_EARNINGS net debit must equal abs(loss)');

        $currentNet = (float) $entries->where('account_id', $current->id)->sum('credit_amount') - (float) $entries->where('account_id', $current->id)->sum('debit_amount');
        $this->assertEqualsWithDelta(0, $currentNet, 0.01, 'CURRENT_EARNINGS must net to zero');

        $this->assertEqualsWithDelta($entries->sum('debit_amount'), $entries->sum('credit_amount'), 0.01);
    }

    /** @test */
    public function api_close_returns_expected_shape_and_get_close_run_returns_run(): void
    {
        $this->postProfitLossEntries(80, 20);

        $service = $this->app->make(PeriodCloseService::class);
        $result = $service->closeCropCycle($this->tenant->id, $this->cropCycle->id, $this->tenantAdmin->id, null);

        $this->assertSame($this->cropCycle->id, $result['crop_cycle']->id);
        $this->assertSame('CLOSED', $result['crop_cycle']->status);
        $this->assertSame('60.00', $result['net_profit']);
        $this->assertArrayHasKey('posting_group_id', $result);
        $this->assertArrayHasKey('closed_at', $result);
        $this->assertArrayHasKey('closed_by_user_id', $result);

        $run = $service->getCloseRun($this->tenant->id, $this->cropCycle->id);
        $this->assertNotNull($run);
        $this->assertEqualsWithDelta(60, (float) $run->net_profit, 0.01);
        $this->assertSame($this->cropCycle->id, $run->crop_cycle_id);
    }

    /** @test */
    public function api_close_run_returns_404_when_not_closed(): void
    {
        $run = $this->app->make(PeriodCloseService::class)->getCloseRun($this->tenant->id, $this->cropCycle->id);
        $this->assertNull($run);
    }

    /** @test */
    public function api_close_run_returns_404_for_other_tenant_cycle(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($otherTenant->id);

        $this->postProfitLossEntries(50, 10);
        $this->app->make(PeriodCloseService::class)->closeCropCycle($this->tenant->id, $this->cropCycle->id, null, null);

        $runForOther = $this->app->make(PeriodCloseService::class)->getCloseRun($otherTenant->id, $this->cropCycle->id);
        $this->assertNull($runForOther);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->app->make(PeriodCloseService::class)->closeCropCycle($otherTenant->id, $this->cropCycle->id, null, null);
    }
}
