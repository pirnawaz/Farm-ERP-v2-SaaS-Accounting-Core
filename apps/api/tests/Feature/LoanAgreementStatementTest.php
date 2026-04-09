<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanDrawdown;
use App\Domains\Accounting\Loans\LoanRepayment;
use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanAgreementStatementTest extends TestCase
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

    public function test_statement_returns_balances_after_posted_movements(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Stmt Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
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
            'reference_no' => 'LA-ST',
            'principal_amount' => 10000,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $dd = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-01',
            'amount' => 1000.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$dd->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'dd-stmt-1',
                'funding_account' => 'BANK',
            ])
            ->assertStatus(201);

        $rp = LoanRepayment::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'repayment_date' => '2024-06-20',
            'amount' => 400.00,
            'principal_amount' => 300.00,
            'interest_amount' => 100.00,
            'status' => LoanRepayment::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-repayments/{$rp->id}/post", [
                'posting_date' => '2024-06-25',
                'idempotency_key' => 'rp-stmt-1',
                'funding_account' => 'CASH',
            ])
            ->assertStatus(201);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/loan-agreements/{$agreement->id}/statement");

        $res->assertStatus(200);
        $res->assertJsonPath('opening_balance', '0.00');
        $res->assertJsonPath('closing_balance', '700.00');
        $res->assertJsonPath('currency_code', 'GBP');
        $this->assertCount(1, $res->json('drawdowns'));
        $this->assertCount(1, $res->json('repayments'));
        $this->assertCount(2, $res->json('lines'));
    }

    public function test_index_and_show_require_loans_module(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active', 'currency_code' => 'GBP']);
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
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Pr',
            'status' => 'ACTIVE',
        ]);
        $agreement = LoanAgreement::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'lender_party_id' => $party->id,
            'reference_no' => 'X1',
            'principal_amount' => 100,
            'currency_code' => 'GBP',
            'status' => LoanAgreement::STATUS_ACTIVE,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/loan-agreements')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $agreement->id);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'operator')
            ->getJson("/api/loan-agreements/{$agreement->id}")
            ->assertStatus(200)
            ->assertJsonPath('reference_no', 'X1');
    }
}
