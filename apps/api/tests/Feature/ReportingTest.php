<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Project;
use App\Models\OperationalTransaction;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $tenant2;
    private Project $project;
    private CropCycle $cropCycle;

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

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\TenantContext::clear();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModules($this->tenant, ['reports']);

        $this->tenant2 = Tenant::create(['name' => 'Test Tenant 2', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant2->id);
        $this->enableModules($this->tenant2, ['reports']);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Party',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Project',
            'crop_cycle_id' => $this->cropCycle->id,
            'party_id' => $party->id,
        ]);
    }

    public function test_trial_balance_uses_posting_date_not_event_date(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-10',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'tb-1',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-15&to=2024-01-15');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data);

        $expRow = collect($data)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNotNull($expRow);
        $this->assertEquals('100.00', $expRow['total_debit']);

        $response2 = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-10&to=2024-01-14');

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $expRow2 = collect($data2)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNull($expRow2);
    }

    public function test_reversal_nets_correctly_in_trial_balance(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $postResp = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'rev-1',
            ]);
        $postResp->assertStatus(201);
        $postingGroupId = $postResp->json('id');

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-15&to=2024-01-15');

        $response->assertStatus(200);
        $data = $response->json();
        $expRow = collect($data)->firstWhere('account_code', 'EXP_SHARED');
        $cashRow = collect($data)->firstWhere('account_code', 'CASH');
        $this->assertNotNull($expRow);
        $this->assertEquals('100.00', $expRow['total_debit']);
        $this->assertNotNull($cashRow);
        $this->assertEquals('100.00', $cashRow['total_credit']);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/posting-groups/{$postingGroupId}/reverse", [
                'posting_date' => '2024-01-20',
                'reason' => 'Correction',
            ])
            ->assertStatus(201);

        $response2 = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-15&to=2024-01-20');

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $expRow2 = collect($data2)->firstWhere('account_code', 'EXP_SHARED');
        $cashRow2 = collect($data2)->firstWhere('account_code', 'CASH');
        $this->assertNotNull($expRow2);
        $this->assertEquals('0.00', $expRow2['net']);
        $this->assertNotNull($cashRow2);
        $this->assertEquals('0.00', $cashRow2['net']);
    }

    public function test_tenant_isolation_in_reports(): void
    {
        $cropCycle2 = CropCycle::create([
            'tenant_id' => $this->tenant2->id,
            'name' => '2024 Cycle 2',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $party2 = Party::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Party 2',
            'party_types' => ['HARI'],
        ]);

        $project2 = Project::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Test Project 2',
            'crop_cycle_id' => $cropCycle2->id,
            'party_id' => $party2->id,
        ]);

        $entry1 = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $entry2 = OperationalTransaction::create([
            'tenant_id' => $this->tenant2->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cropCycle2->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 200.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry1->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'iso-1',
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $this->tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry2->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'iso-2',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-15&to=2024-01-15');

        $response->assertStatus(200);
        $data = $response->json();
        $expRow = collect($data)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNotNull($expRow);
        $this->assertEquals('100.00', $expRow['total_debit']);

        $response2 = $this->withHeader('X-Tenant-Id', $this->tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/trial-balance?from=2024-01-15&to=2024-01-15');

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $expRow2 = collect($data2)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNotNull($expRow2);
        $this->assertEquals('200.00', $expRow2['total_debit']);
    }

    public function test_general_ledger_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => sprintf('2024-01-%02d', $i),
            'amount' => 10.00 * $i,
            'classification' => 'SHARED',
        ]);

            $this->withHeader('X-Tenant-Id', $this->tenant->id)
                ->withHeader('X-User-Role', 'accountant')
                ->postJson("/api/operational-transactions/{$entry->id}/post", [
                    'posting_date' => sprintf('2024-01-%02d', $i),
                    'idempotency_key' => "gl-pg-{$i}",
                ])
                ->assertStatus(201);
        }

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?from=2024-01-01&to=2024-01-31&page=1&per_page=2');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertCount(2, $data['data']);
        $this->assertEquals(1, $data['pagination']['page']);
        $this->assertGreaterThan(2, $data['pagination']['total']);
    }

    public function test_general_ledger_shows_reversal_tags(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $postResp = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'gl-rev-1',
            ]);
        $postResp->assertStatus(201);
        $postingGroupId = $postResp->json('id');

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/posting-groups/{$postingGroupId}/reverse", [
                'posting_date' => '2024-01-20',
                'reason' => 'Correction',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/general-ledger?from=2024-01-15&to=2024-01-20');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('data', $data);

        $reversalRows = collect($data['data'])->filter(fn ($row) => ($row['reversal_of_posting_group_id'] ?? null) !== null);
        $this->assertGreaterThan(0, $reversalRows->count());
    }

    public function test_project_pl_calculation(): void
    {
        $expense = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $income = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-20',
            'amount' => 500.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$expense->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'pl-exp-1',
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$income->id}/post", [
                'posting_date' => '2024-01-20',
                'idempotency_key' => 'pl-inc-1',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-pl?from=2024-01-01&to=2024-01-31');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertNotEmpty($data);

        $projectRow = collect($data)->firstWhere('project_id', $this->project->id);
        $this->assertNotNull($projectRow);
        $this->assertEquals('500.00', $projectRow['income']);
        $this->assertEquals('100.00', $projectRow['expenses']);
        $this->assertEquals('400.00', $projectRow['net_profit']);
    }

    public function test_account_balances_as_of_date(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'ab-1',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/account-balances?as_of=2024-01-14');

        $response->assertStatus(200);
        $data = $response->json();
        $expRow = collect($data)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNull($expRow);

        $response2 = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/account-balances?as_of=2024-01-15');

        $response2->assertStatus(200);
        $data2 = $response2->json();
        $expRow2 = collect($data2)->firstWhere('account_code', 'EXP_SHARED');
        $this->assertNotNull($expRow2);
        $this->assertEquals('100.00', $expRow2['debits']);
    }
}
