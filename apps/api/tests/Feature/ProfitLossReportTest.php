<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'P&L Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        return $tenant;
    }

    private function createCropCycleAndProject(Tenant $tenant): array
    {
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Farm Party',
            'party_types' => ['FARM'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project A',
            'status' => 'ACTIVE',
        ]);
        return ['crop_cycle' => $cropCycle, 'project' => $project, 'party' => $party];
    }

    /**
     * Create posting_group + ledger_entries; optionally link to project via allocation_row.
     */
    private function postEntries(Tenant $tenant, string $postingDate, array $lines, ?string $cropCycleId = null, ?string $projectId = null, ?string $partyId = null): PostingGroup
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
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
                'currency_code' => $line['currency_code'] ?? 'GBP',
            ]);
        }
        if ($projectId !== null && $partyId !== null) {
            AllocationRow::create([
                'tenant_id' => $tenant->id,
                'posting_group_id' => $pg->id,
                'project_id' => $projectId,
                'party_id' => $partyId,
                'allocation_type' => 'POOL_SHARE',
                'amount' => 1,
            ]);
        }
        return $pg;
    }

    /**
     * Project P&L: postings to REVENUE and EXPENSE scoped to project; assert totals and row amounts.
     */
    public function test_project_pl_aggregation(): void
    {
        $tenant = $this->tenantWithAccounts();
        $setup = $this->createCropCycleAndProject($tenant);
        $cropCycle = $setup['crop_cycle'];
        $project = $setup['project'];
        $party = $setup['party'];

        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $expense = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->first();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $this->assertNotNull($revenue);
        $this->assertNotNull($expense);

        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ], $cropCycle->id, $project->id, $party->id);
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $expense->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ], $cropCycle->id, $project->id, $party->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/project?project_id=' . $project->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame($tenant->id, $data['meta']['tenant_id']);
        $this->assertSame('2024-01-01', $data['meta']['from']);
        $this->assertSame('2024-01-31', $data['meta']['to']);
        $this->assertEqualsWithDelta(500.0, (float) $data['totals']['income_total'], 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $data['totals']['expense_total'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $data['totals']['net_profit'], 0.01);
        $this->assertCount(1, $data['rows']['income']);
        $this->assertEqualsWithDelta(500.0, (float) $data['rows']['income'][0]['amount'], 0.01);
        $this->assertCount(1, $data['rows']['expenses']);
        $this->assertEqualsWithDelta(200.0, (float) $data['rows']['expenses'][0]['amount'], 0.01);
    }

    /**
     * Crop cycle P&L: two projects in same crop cycle; post to both; report includes both.
     */
    public function test_crop_cycle_pl_includes_both_projects(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party1 = Party::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'party_types' => ['FARM']]);
        $party2 = Party::create(['tenant_id' => $tenant->id, 'name' => 'P2', 'party_types' => ['FARM']]);
        $projectA = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party1->id, 'crop_cycle_id' => $cropCycle->id, 'name' => 'A', 'status' => 'ACTIVE']);
        $projectB = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cropCycle->id, 'name' => 'B', 'status' => 'ACTIVE']);

        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();

        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ], $cropCycle->id, $projectA->id, $party1->id);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ], $cropCycle->id, $projectB->id, $party2->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/crop-cycle?crop_cycle_id=' . $cropCycle->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(300.0, (float) $data['totals']['income_total'], 0.01, 'Both projects in cycle');
        $this->assertEqualsWithDelta(300.0, (float) $data['totals']['net_profit'], 0.01);
    }

    /**
     * Project filter: two projects in same crop cycle; report for project A excludes project B.
     */
    public function test_project_filter_excludes_other_project_in_same_cycle(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party1 = Party::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'party_types' => ['FARM']]);
        $party2 = Party::create(['tenant_id' => $tenant->id, 'name' => 'P2', 'party_types' => ['FARM']]);
        $projectA = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party1->id, 'crop_cycle_id' => $cropCycle->id, 'name' => 'A', 'status' => 'ACTIVE']);
        $projectB = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cropCycle->id, 'name' => 'B', 'status' => 'ACTIVE']);

        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();

        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ], $cropCycle->id, $projectA->id, $party1->id);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 999, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 999],
        ], $cropCycle->id, $projectB->id, $party2->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/project?project_id=' . $projectA->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['income_total'], 0.01, 'Only project A');
        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['net_profit'], 0.01);
    }

    /**
     * Date range: postings before/within/after; only within included.
     */
    public function test_date_range_behavior(): void
    {
        $tenant = $this->tenantWithAccounts();
        $setup = $this->createCropCycleAndProject($tenant);
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();

        $this->postEntries($tenant, '2024-01-05', [
            ['account_id' => $bank->id, 'debit_amount' => 50, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 50],
        ], $setup['crop_cycle']->id, $setup['project']->id, $setup['party']->id);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ], $setup['crop_cycle']->id, $setup['project']->id, $setup['party']->id);
        $this->postEntries($tenant, '2024-02-10', [
            ['account_id' => $bank->id, 'debit_amount' => 200, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 200],
        ], $setup['crop_cycle']->id, $setup['project']->id, $setup['party']->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/project?project_id=' . $setup['project']->id . '&from=2024-01-10&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['income_total'], 0.01, 'Only Jan 15 in range');
    }

    /**
     * Tenant isolation: two tenants; report for tenant1 excludes tenant2.
     */
    public function test_tenant_isolation(): void
    {
        $tenant1 = $this->tenantWithAccounts();
        $tenant2 = $this->tenantWithAccounts();
        $setup1 = $this->createCropCycleAndProject($tenant1);
        $setup2 = $this->createCropCycleAndProject($tenant2);

        $revenue1 = Account::where('tenant_id', $tenant1->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank1 = Account::where('tenant_id', $tenant1->id)->where('code', 'BANK')->first();
        $revenue2 = Account::where('tenant_id', $tenant2->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank2 = Account::where('tenant_id', $tenant2->id)->where('code', 'BANK')->first();

        $this->postEntries($tenant1, '2024-01-15', [
            ['account_id' => $bank1->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $revenue1->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ], $setup1['crop_cycle']->id, $setup1['project']->id, $setup1['party']->id);
        $this->postEntries($tenant2, '2024-01-15', [
            ['account_id' => $bank2->id, 'debit_amount' => 9999, 'credit_amount' => 0],
            ['account_id' => $revenue2->id, 'debit_amount' => 0, 'credit_amount' => 9999],
        ], $setup2['crop_cycle']->id, $setup2['project']->id, $setup2['party']->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/project?project_id=' . $setup1['project']->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['income_total'], 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['net_profit'], 0.01);
    }

    /**
     * Reversal: original + reversal posting group; both excluded, no impact on P&L.
     */
    public function test_reversal_pair_excluded(): void
    {
        $tenant = $this->tenantWithAccounts();
        $setup = $this->createCropCycleAndProject($tenant);
        $revenue = Account::where('tenant_id', $tenant->id)->where('code', 'PROJECT_REVENUE')->first();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();

        $original = $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ], $setup['crop_cycle']->id, $setup['project']->id, $setup['party']->id);

        $pgRev = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $setup['crop_cycle']->id,
            'source_type' => 'REVERSAL',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-01-20',
            'idempotency_key' => 'test-rev-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => $original->id,
        ]);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pgRev->id, 'account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 500, 'currency_code' => 'GBP']);
        LedgerEntry::create(['tenant_id' => $tenant->id, 'posting_group_id' => $pgRev->id, 'account_id' => $revenue->id, 'debit_amount' => 500, 'credit_amount' => 0, 'currency_code' => 'GBP']);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgRev->id,
            'project_id' => $setup['project']->id,
            'party_id' => $setup['party']->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 1,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/profit-loss/crop-cycle?crop_cycle_id=' . $setup['crop_cycle']->id . '&from=2024-01-01&to=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['income_total'], 0.01, 'Reversal pair excluded');
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['net_profit'], 0.01);
    }

    /**
     * Validation: missing required params return 422; to < from return 422.
     */
    public function test_validation_required_params_and_to_after_from(): void
    {
        $tenant = $this->tenantWithAccounts();
        $setup = $this->createCropCycleAndProject($tenant);
        $base = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $r1 = $this->withHeaders($base)->getJson('/api/reports/profit-loss/project?from=2024-01-01&to=2024-01-31');
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['project_id']);

        $r2 = $this->withHeaders($base)->getJson('/api/reports/profit-loss/project?project_id=' . $setup['project']->id . '&to=2024-01-31');
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['from']);

        $r3 = $this->withHeaders($base)->getJson('/api/reports/profit-loss/project?project_id=' . $setup['project']->id . '&from=2024-01-01');
        $r3->assertStatus(422);
        $r3->assertJsonValidationErrors(['to']);

        $r4 = $this->withHeaders($base)->getJson('/api/reports/profit-loss/project?project_id=' . $setup['project']->id . '&from=2024-01-31&to=2024-01-01');
        $r4->assertStatus(422);
        $r4->assertJsonValidationErrors(['to']);

        $r5 = $this->withHeaders($base)->getJson('/api/reports/profit-loss/crop-cycle?from=2024-01-01&to=2024-01-31');
        $r5->assertStatus(422);
        $r5->assertJsonValidationErrors(['crop_cycle_id']);
    }
}
