<?php

namespace Tests\Feature;

use App\Domains\Accounting\Loans\LoanAgreement;
use App\Domains\Accounting\Loans\LoanDrawdown;
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

class LoanDrawdownPostingTest extends TestCase
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

    /** @return array{tenant: Tenant, project: Project, drawdown: LoanDrawdown} */
    private function createFixtures(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Loan Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
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

        $drawdown = LoanDrawdown::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'loan_agreement_id' => $agreement->id,
            'drawdown_date' => '2024-06-15',
            'amount' => 1500.00,
            'status' => LoanDrawdown::STATUS_DRAFT,
        ]);

        return ['tenant' => $tenant, 'project' => $project, 'drawdown' => $drawdown];
    }

    public function test_post_loan_drawdown_creates_balanced_ledger(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $drawdown = $data['drawdown'];

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'test-key-1',
                'funding_account' => 'BANK',
            ]);

        $res->assertStatus(201);
        $pgId = $res->json('id');
        $this->assertNotEmpty($pgId);

        $debits = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $credits = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(1500.00, $debits, 0.01);
        $this->assertEqualsWithDelta(1500.00, $credits, 0.01);

        $drawdown->refresh();
        $this->assertSame(LoanDrawdown::STATUS_POSTED, $drawdown->status);
        $this->assertNotNull($drawdown->posting_group_id);
    }

    public function test_post_is_idempotent_when_same_idempotency_key_repeated(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $drawdown = $data['drawdown'];

        $payload = [
            'posting_date' => '2024-06-20',
            'idempotency_key' => 'same-key',
            'funding_account' => 'CASH',
        ];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", $payload);
        $r1->assertStatus(201);
        $id1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", $payload);
        $r2->assertStatus(201);
        $this->assertSame($id1, $r2->json('id'));
    }

    public function test_post_fails_when_crop_cycle_closed(): void
    {
        $data = $this->createFixtures();
        $tenant = $data['tenant'];
        $drawdown = $data['drawdown'];
        $project = $data['project'];

        CropCycle::where('id', $project->crop_cycle_id)->update(['status' => 'CLOSED']);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/loan-drawdowns/{$drawdown->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'closed-cycle',
                'funding_account' => 'CASH',
            ]);

        $res->assertStatus(422);
    }
}
