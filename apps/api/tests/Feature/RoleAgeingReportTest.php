<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\Account;
use App\Services\PartyAccountService;
use App\Services\RoleAgeingService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class RoleAgeingReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Project $project;
    private Account $partyControlHari;
    private Account $partyControlLandlord;

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::clear();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModule($this->tenant, 'reports');

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $this->hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $partyAccountService = app(PartyAccountService::class);
        $this->partyControlHari = $partyAccountService->getPartyControlAccountByRole($this->tenant->id, 'HARI');
        $this->partyControlLandlord = $partyAccountService->getPartyControlAccountByRole($this->tenant->id, 'LANDLORD');
        $this->assertEquals('PARTY_CONTROL_HARI', $this->partyControlHari->code);
        $this->assertEquals('PARTY_CONTROL_LANDLORD', $this->partyControlLandlord->code);
    }

    private function enableModule(Tenant $tenant, string $key): void
    {
        $module = Module::where('key', $key)->first();
        if ($module) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /**
     * Create posting group + ledger entry to PARTY_CONTROL_* account. Optionally add allocation_row for project filter.
     */
    private function createPostingGroupWithEntry(
        string $postingDate,
        string $accountId,
        float $debit,
        float $credit,
        ?string $projectId = null
    ): PostingGroup {
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => $postingDate,
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $accountId,
            'debit_amount' => (string) $debit,
            'credit_amount' => (string) $credit,
            'currency_code' => 'GBP',
        ]);
        if ($projectId !== null) {
            AllocationRow::create([
                'tenant_id' => $this->tenant->id,
                'posting_group_id' => $pg->id,
                'project_id' => $projectId,
                'party_id' => $this->hariParty->id,
                'allocation_type' => 'POOL_SHARE',
                'amount' => abs($debit - $credit),
            ]);
        }
        return $pg;
    }

    public function test_role_ageing_returns_buckets_and_totals(): void
    {
        // as_of = 2024-07-15 → 0-30: > 2024-06-15 & <= 2024-07-15; 31-60: > 2024-05-16 & <= 2024-06-15; 61-90: > 2024-04-16 & <= 2024-05-16; 90+: <= 2024-04-16
        $this->createPostingGroupWithEntry('2024-07-10', $this->partyControlHari->id, 10.00, 0, $this->project->id); // 0-30
        $this->createPostingGroupWithEntry('2024-06-10', $this->partyControlHari->id, 20.00, 0, $this->project->id); // 31-60
        $this->createPostingGroupWithEntry('2024-05-10', $this->partyControlHari->id, 30.00, 0, $this->project->id); // 61-90
        $this->createPostingGroupWithEntry('2024-04-01', $this->partyControlHari->id, 40.00, 0, $this->project->id); // 90+

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/role-ageing?as_of=2024-07-15');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('as_of', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertEquals('2024-07-15', $data['as_of']);

        $hariRow = collect($data['rows'])->firstWhere('role', 'HARI');
        $this->assertNotNull($hariRow);
        $this->assertEquals('All Haris', $hariRow['label']);
        $this->assertEqualsWithDelta(10.0, $hariRow['bucket_0_30'], 0.01);
        $this->assertEqualsWithDelta(20.0, $hariRow['bucket_31_60'], 0.01);
        $this->assertEqualsWithDelta(30.0, $hariRow['bucket_61_90'], 0.01);
        $this->assertEqualsWithDelta(40.0, $hariRow['bucket_90_plus'], 0.01);
        $this->assertEqualsWithDelta(100.0, $hariRow['total_balance'], 0.01);

        $totals = $data['totals'];
        $this->assertEqualsWithDelta(10.0, $totals['bucket_0_30'], 0.01);
        $this->assertEqualsWithDelta(20.0, $totals['bucket_31_60'], 0.01);
        $this->assertEqualsWithDelta(30.0, $totals['bucket_61_90'], 0.01);
        $this->assertEqualsWithDelta(40.0, $totals['bucket_90_plus'], 0.01);
        $this->assertEqualsWithDelta(100.0, $totals['total_balance'], 0.01);

        $sumBucket0 = array_sum(array_column($data['rows'], 'bucket_0_30'));
        $this->assertEqualsWithDelta($totals['bucket_0_30'], $sumBucket0, 0.01);
    }

    public function test_role_ageing_multiple_roles(): void
    {
        $this->createPostingGroupWithEntry('2024-07-10', $this->partyControlHari->id, 50.00, 0, $this->project->id);
        $this->createPostingGroupWithEntry('2024-06-10', $this->partyControlLandlord->id, 25.00, 0, $this->project->id);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/role-ageing?as_of=2024-07-15');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data['rows']);

        $hariRow = collect($data['rows'])->firstWhere('role', 'HARI');
        $landlordRow = collect($data['rows'])->firstWhere('role', 'LANDLORD');
        $this->assertNotNull($hariRow);
        $this->assertNotNull($landlordRow);
        $this->assertEqualsWithDelta(50.0, $hariRow['bucket_0_30'], 0.01);
        $this->assertEqualsWithDelta(50.0, $hariRow['total_balance'], 0.01);
        $this->assertEqualsWithDelta(25.0, $landlordRow['bucket_31_60'], 0.01);
        $this->assertEqualsWithDelta(25.0, $landlordRow['total_balance'], 0.01);

        $this->assertEqualsWithDelta(75.0, $data['totals']['total_balance'], 0.01);
    }

    public function test_role_ageing_project_filter_includes_only_that_project(): void
    {
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Other Project',
            'status' => 'ACTIVE',
        ]);

        $this->createPostingGroupWithEntry('2024-07-10', $this->partyControlHari->id, 100.00, 0, $this->project->id);
        $this->createPostingGroupWithEntry('2024-07-11', $this->partyControlHari->id, 50.00, 0, $otherProject->id);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/role-ageing?as_of=2024-07-15&project_id=' . $this->project->id);

        $response->assertStatus(200);
        $data = $response->json();
        $hariRow = collect($data['rows'])->firstWhere('role', 'HARI');
        $this->assertNotNull($hariRow);
        $this->assertEqualsWithDelta(100.0, $hariRow['bucket_0_30'], 0.01);
        $this->assertEqualsWithDelta(100.0, $hariRow['total_balance'], 0.01);
    }

    public function test_role_ageing_crop_cycle_filter_includes_only_that_crop_cycle(): void
    {
        $otherCropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $otherCropCycle->id,
            'name' => 'Other Project',
            'status' => 'ACTIVE',
        ]);

        $pg1 = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-07-10',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg1->id,
            'account_id' => $this->partyControlHari->id,
            'debit_amount' => '100',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg1->id,
            'project_id' => $this->project->id,
            'party_id' => $this->hariParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 100,
        ]);

        $pg2 = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $otherCropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-07-10',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg2->id,
            'account_id' => $this->partyControlHari->id,
            'debit_amount' => '50',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg2->id,
            'project_id' => $otherProject->id,
            'party_id' => $this->hariParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 50,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/role-ageing?as_of=2024-07-15&crop_cycle_id=' . $this->cropCycle->id);

        $response->assertStatus(200);
        $data = $response->json();
        $hariRow = collect($data['rows'])->firstWhere('role', 'HARI');
        $this->assertNotNull($hariRow);
        $this->assertEqualsWithDelta(100.0, $hariRow['total_balance'], 0.01);
    }

    public function test_role_ageing_validates_missing_as_of_returns_422(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/role-ageing')
            ->assertStatus(422);
    }

    public function test_role_ageing_uses_only_party_control_accounts(): void
    {
        $service = app(RoleAgeingService::class);
        $this->createPostingGroupWithEntry('2024-07-10', $this->partyControlHari->id, 25.00, 0, $this->project->id);

        $result = $service->getAgeing(
            $this->tenant->id,
            '2024-07-15',
            null,
            null
        );

        $hariRow = collect($result['rows'])->firstWhere('role', 'HARI');
        $this->assertNotNull($hariRow);
        $this->assertEqualsWithDelta(25.0, $hariRow['bucket_0_30'], 0.01);
        $this->assertEqualsWithDelta(25.0, $hariRow['total_balance'], 0.01);
        // Service only queries PARTY_CONTROL_* account IDs; no legacy ADVANCE_*/DUE_FROM_*/PAYABLE_*
    }
}
