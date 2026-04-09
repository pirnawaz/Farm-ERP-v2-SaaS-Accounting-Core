<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanRepayment;
use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanRepaymentPostingTest extends TestCase
{
    use RefreshDatabase;

    private function enableLoans(Tenant $tenant): void
    {
        $m = Module::where('key', 'loans')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /** @return array{tenant: Tenant, repayment: LoanRepayment} */
    private function createFixtures(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Loan Repay Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLoans($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Lender',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-1',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $repayment = LoanRepayment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'repayment_date' => '2024-07-01',
            'amount' => 1000.00,
            'principal_amount' => 700.00,
            'interest_amount' => 300.00,
            'status' => LoanRepayment::STATUS_DRAFT,
        ]);

        return ['tenant' => $tenant, 'repayment' => $repayment];
    }

    public function test_post_creates_three_ledger_lines_balanced(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $repayment = $data['repayment'];

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'repay-key-1',
                'funding_account' => 'BANK',
            ]);

        $res->assertStatus(201);
        $pgId = $res->json('id');

        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $this->assertCount(3, $entries);

        $loanPayableId = Account::where('tenant_id', $tenant->id)->where('code', 'LOAN_PAYABLE')->value('id');
        $interestId = Account::where('tenant_id', $tenant->id)->where('code', 'LOAN_INTEREST_EXPENSE')->value('id');
        $bankId = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->value('id');

        $drLoan = (float) $entries->where('account_id', $loanPayableId)->sum('debit_amount');
        $drInt = (float) $entries->where('account_id', $interestId)->sum('debit_amount');
        $crBank = (float) $entries->where('account_id', $bankId)->sum('credit_amount');

        $this->assertEqualsWithDelta(700.0, $drLoan, 0.02);
        $this->assertEqualsWithDelta(300.0, $drInt, 0.02);
        $this->assertEqualsWithDelta(1000.0, $crBank, 0.02);

        $repayment->refresh();
        $this->assertSame(LoanRepayment::STATUS_POSTED, $repayment->status);
        $this->assertNotNull($repayment->posting_group_id);
    }

    public function test_post_idempotent_same_key(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $repayment = $data['repayment'];

        $payload = [
            'posting_date' => '2024-07-05',
            'idempotency_key' => 'same-repay',
            'funding_account' => 'CASH',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", $payload);
        $r1->assertStatus(201);
        $id1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", $payload);
        $r2->assertStatus(201);
        $this->assertSame($id1, $r2->json('id'));
    }

    public function test_principal_only_defaults_interest_to_zero(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLoans($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'L',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'LA-2',
            'principal_amount' => 5000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);
        $repayment = LoanRepayment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'repayment_date' => '2024-07-01',
            'amount' => 500.00,
            'principal_amount' => null,
            'interest_amount' => null,
            'status' => LoanRepayment::STATUS_DRAFT,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'principal-only',
                'funding_account' => 'CASH',
            ]);

        $res->assertStatus(201);
        $pgId = $res->json('id');
        $loanPayableId = Account::where('tenant_id', $tenant->id)->where('code', 'LOAN_PAYABLE')->value('id');
        $interestId = Account::where('tenant_id', $tenant->id)->where('code', 'LOAN_INTEREST_EXPENSE')->value('id');

        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $this->assertCount(2, $entries);
        $drLoan = (float) $entries->where('account_id', $loanPayableId)->sum('debit_amount');
        $drInt = (float) $entries->where('account_id', $interestId)->sum('debit_amount');
        $this->assertEqualsWithDelta(500.0, $drLoan, 0.02);
        $this->assertEqualsWithDelta(0.0, $drInt, 0.02);
    }

    public function test_post_fails_when_crop_cycle_closed(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $repayment = $data['repayment'];
        $project = Project::where('id', $repayment->project_id)->firstOrFail();

        CropCycle::where('id', $project->crop_cycle_id)->update(['status' => 'CLOSED']);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$repayment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'closed-repay',
                'funding_account' => 'CASH',
            ]);

        $res->assertStatus(422);
    }
}
