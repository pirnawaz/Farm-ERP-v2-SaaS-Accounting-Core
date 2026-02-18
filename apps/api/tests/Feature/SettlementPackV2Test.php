<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\SettlementPack;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;

class SettlementPackV2Test extends TestCase
{
    use RefreshDatabase;

    private function enableSettlements(Tenant $tenant): void
    {
        $m = Module::where('key', 'settlements')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /** Create tenant with project and postings that affect ledger (revenue + expense + bank + equity) for financial statements. */
    private function createTenantWithProjectAndLedgerPostings(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Pack V2 Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($revenue);
        $this->assertNotNull($expense);
        $this->assertNotNull($equity);

        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'v2-test-' . \Illuminate\Support\Str::uuid(),
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg->id, 'account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $pg2 = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-20',
            'idempotency_key' => 'v2-test-2-' . \Illuminate\Support\Str::uuid(),
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg2->id, 'account_id' => $expense->id, 'debit_amount' => 200, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pg2->id, 'account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 200, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg2->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $userAdmin = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin-v2@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $userAccountant = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accountant',
            'email' => 'accountant-v2@test.' . $tenant->id,
            'password' => Hash::make('password'),
            'role' => 'accountant',
            'is_enabled' => true,
        ]);

        return ['tenant' => $tenant, 'project' => $project, 'party' => $party, 'cycle' => $cycle, 'user_admin' => $userAdmin, 'user_accountant' => $userAccountant];
    }

    /** Submit pack for approval then have both required roles approve (v4 workflow). */
    private function submitAndApproveAll(string $tenantId, string $packId, array $data): void
    {
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval")
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_admin']->id])
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenantId)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/approve", ['approver_user_id' => $data['user_accountant']->id])
            ->assertStatus(200);
    }

    public function test_generate_pack_embeds_financial_statements(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);

        $response->assertStatus(201);
        $body = $response->json();

        $this->assertArrayHasKey('summary_json', $body);
        $summary = $body['summary_json'];
        $this->assertArrayHasKey('financial_statements', $summary);
        $fs = $summary['financial_statements'];
        $this->assertArrayHasKey('trial_balance', $fs);
        $this->assertArrayHasKey('profit_loss', $fs);
        $this->assertArrayHasKey('balance_sheet', $fs);

        $tb = $fs['trial_balance'];
        $this->assertArrayHasKey('rows', $tb);
        $this->assertArrayHasKey('totals', $tb);
        $this->assertArrayHasKey('balanced', $tb);

        $pl = $fs['profit_loss'];
        $this->assertArrayHasKey('totals', $pl);
        $this->assertEqualsWithDelta(500.0, (float) ($pl['totals']['income_total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(200.0, (float) ($pl['totals']['expense_total'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(300.0, (float) ($pl['totals']['net_profit'] ?? 0), 0.01);

        $bs = $fs['balance_sheet'];
        $this->assertArrayHasKey('totals', $bs);
        $this->assertTrue($bs['totals']['balanced'] ?? false, 'Balance sheet should be balanced');
    }

    public function test_snapshot_immutability_after_new_posting(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $r1->assertStatus(201);
        $packId = $r1->json('id');
        $originalPlTotal = $r1->json('summary_json.financial_statements.profit_loss.totals.net_profit');

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $pgNew = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $data['cycle']->id,
            'source_type' => 'JOURNAL_ENTRY',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-07-01',
            'idempotency_key' => 'v2-extra-' . \Illuminate\Support\Str::uuid(),
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pgNew->id, 'account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pgNew->id, 'account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 100, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgNew->id,
            'project_id' => $project->id,
            'party_id' => $data['party']->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $getResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}");
        $getResponse->assertStatus(200);
        $summary = $getResponse->json('summary_json');
        $this->assertArrayHasKey('financial_statements', $summary);
        $this->assertEqualsWithDelta((float) $originalPlTotal, (float) ($summary['financial_statements']['profit_loss']['totals']['net_profit'] ?? 0), 0.01, 'Embedded P&L snapshot unchanged');
    }

    public function test_finalization_locks_project(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->submitAndApproveAll($tenant->id, $packId, $data);

        $showRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}");
        $showRes->assertStatus(200);
        $this->assertSame('FINAL', $showRes->json('status'));
        $this->assertNotNull($showRes->json('finalized_at'));

        $project->refresh();
        $this->assertSame('CLOSED', $project->status);

        $this->expectException(\App\Exceptions\ProjectClosedException::class);
        $guard = app(\App\Services\OperationalPostingGuard::class);
        $guard->ensureProjectNotClosed($project->id, $tenant->id);
    }

    public function test_cannot_finalize_twice(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $this->submitAndApproveAll($tenant->id, $packId, $data);

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/finalize");
        $r2->assertStatus(200);
        $this->assertSame('FINAL', $r2->json('status'));
    }

    public function test_tenant_isolation_finalize(): void
    {
        $data = $this->createTenantWithProjectAndLedgerPostings();
        $tenant1 = $data['tenant'];
        $project1 = $data['project'];

        $createRes = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project1->id}/settlement-pack", []);
        $createRes->assertStatus(201);
        $packId = $createRes->json('id');

        $tenant2 = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant2);

        $submitOther = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/settlement-packs/{$packId}/submit-for-approval");
        $submitOther->assertStatus(404);
    }
}
