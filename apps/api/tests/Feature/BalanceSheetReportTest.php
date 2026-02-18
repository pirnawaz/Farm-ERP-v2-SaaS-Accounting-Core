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

class BalanceSheetReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function tenantWithAccounts(): Tenant
    {
        $tenant = Tenant::create(['name' => 'BS Tenant', 'status' => 'active']);
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

    private function postEntries(Tenant $tenant, string $postingDate, array $lines, ?string $cropCycleId = null, ?string $projectId = null, ?string $partyId = null, ?string $reversalOfId = null): PostingGroup
    {
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => $reversalOfId ? 'REVERSAL' : 'JOURNAL_ENTRY',
            'source_id' => \Illuminate\Support\Str::uuid(),
            'posting_date' => $postingDate,
            'idempotency_key' => 'test-' . \Illuminate\Support\Str::uuid(),
            'reversal_of_posting_group_id' => $reversalOfId,
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
     * Tenant-wide balance sheet: DR BANK (asset), CR EQUITY; equation balances.
     */
    public function test_tenant_wide_balance_sheet_balances(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();
        $this->assertNotNull($bank);
        $this->assertNotNull($equity);

        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();

        $this->assertSame($tenant->id, $data['meta']['tenant_id']);
        $this->assertSame('2024-01-31', $data['meta']['as_of']);
        $this->assertArrayHasKey('sections', $data);
        $this->assertArrayHasKey('totals', $data);
        $totals = $data['totals'];
        $this->assertEqualsWithDelta(1000.0, (float) $totals['assets_total'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $totals['equity_total'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $totals['liabilities_plus_equity_total'], 0.01);
        $this->assertTrue($totals['balanced']);
    }

    /**
     * As-of: postings before and after as_of; only before included.
     */
    public function test_as_of_excludes_future_postings(): void
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
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['assets_total'], 0.01, 'Only Jan posting');
        $this->assertTrue($data['totals']['balanced']);
    }

    /**
     * Crop cycle filter: two cycles; report with crop_cycle_id includes only that cycle.
     */
    public function test_crop_cycle_filter_includes_only_that_cycle(): void
    {
        $tenant = $this->tenantWithAccounts();
        $cycle1 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $cycle2 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 2',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['FARM']]);
        $proj1 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cycle1->id, 'name' => 'P1', 'status' => 'ACTIVE']);
        $proj2 = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cycle2->id, 'name' => 'P2', 'status' => 'ACTIVE']);

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ], $cycle1->id, $proj1->id, $party->id);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 300, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 300],
        ], $cycle2->id, $proj2->id, $party->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31&crop_cycle_id=' . $cycle1->id);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(500.0, (float) $data['totals']['assets_total'], 0.01);
        $this->assertTrue($data['totals']['balanced']);
    }

    /**
     * Project filter: two projects in same crop cycle; report for project A only.
     */
    public function test_project_filter_includes_only_that_project(): void
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

        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 100, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 100],
        ], $cropCycle->id, $projectA->id, $party1->id);
        $this->postEntries($tenant, '2024-01-15', [
            ['account_id' => $bank->id, 'debit_amount' => 999, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 999],
        ], $cropCycle->id, $projectB->id, $party2->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31&project_id=' . $projectA->id);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['assets_total'], 0.01, 'Only project A');
        $this->assertTrue($data['totals']['balanced']);
    }

    /**
     * Tenant isolation: two tenants; report for tenant1 excludes tenant2.
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
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(100.0, (float) $data['totals']['assets_total'], 0.01);
        $this->assertTrue($data['totals']['balanced']);
    }

    /**
     * Reversal: original + reversal pair <= as_of; both excluded, no impact.
     */
    public function test_reversal_pair_excluded(): void
    {
        $tenant = $this->tenantWithAccounts();
        $bank = Account::where('tenant_id', $tenant->id)->where('code', 'BANK')->first();
        $equity = Account::where('tenant_id', $tenant->id)->where('code', 'PROFIT_DISTRIBUTION')->first();

        $original = $this->postEntries($tenant, '2024-01-10', [
            ['account_id' => $bank->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $equity->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ]);
        $this->postEntries($tenant, '2024-01-20', [
            ['account_id' => $bank->id, 'debit_amount' => 0, 'credit_amount' => 500],
            ['account_id' => $equity->id, 'debit_amount' => 500, 'credit_amount' => 0],
        ], null, null, null, $original->id);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/balance-sheet?as_of=2024-01-31');
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['assets_total'], 0.01, 'Reversal pair excluded');
        $this->assertEqualsWithDelta(0.0, (float) $data['totals']['equity_total'], 0.01);
        $this->assertTrue($data['totals']['balanced']);
    }

    /**
     * Validation: missing as_of => 422; invalid UUID => 422.
     */
    public function test_validation_missing_as_of_and_invalid_uuid(): void
    {
        $tenant = $this->tenantWithAccounts();
        $base = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $r1 = $this->withHeaders($base)->getJson('/api/reports/balance-sheet');
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['as_of']);

        $r2 = $this->withHeaders($base)->getJson('/api/reports/balance-sheet?as_of=2024-01-31&crop_cycle_id=not-a-uuid');
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['crop_cycle_id']);

        $r3 = $this->withHeaders($base)->getJson('/api/reports/balance-sheet?as_of=2024-01-31&project_id=invalid');
        $r3->assertStatus(422);
        $r3->assertJsonValidationErrors(['project_id']);
    }
}
