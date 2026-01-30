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
use App\Services\PartySummaryService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class PartySummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Party $landlordParty;
    private Party $kamdarParty;
    private Project $project;
    private Account $partyControlHari;
    private Account $partyControlLandlord;
    private Account $partyControlKamdar;

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
        $this->landlordParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $this->kamdarParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kamdar',
            'party_types' => ['KAMDAR'],
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
        $this->partyControlKamdar = $partyAccountService->getPartyControlAccountByRole($this->tenant->id, 'KAMDAR');
        $this->assertEquals('PARTY_CONTROL_HARI', $this->partyControlHari->code);
        $this->assertEquals('PARTY_CONTROL_LANDLORD', $this->partyControlLandlord->code);
        $this->assertEquals('PARTY_CONTROL_KAMDAR', $this->partyControlKamdar->code);
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
     * Create a posting group with one ledger entry to a PARTY_CONTROL_* account and allocation row(s)
     * so the entry is attributed to the given party.
     */
    private function createPostingGroupWithPartyEntry(
        string $postingDate,
        string $accountId,
        string $partyId,
        string $projectId,
        float $debit,
        float $credit
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
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $projectId,
            'party_id' => $partyId,
            'allocation_type' => 'POOL_SHARE',
            'amount' => abs($debit - $credit), // allocation amount must be >= 0; report uses LE for totals
        ]);
        return $pg;
    }

    public function test_party_summary_returns_opening_movement_closing_and_totals(): void
    {
        // Opening: Hari has 100 before from (2024-05-15)
        $this->createPostingGroupWithPartyEntry(
            '2024-05-15',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            100.00,
            0
        );
        // Movement: Hari +50 -30 in June
        $this->createPostingGroupWithPartyEntry(
            '2024-06-01',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            50.00,
            0
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-06-15',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            0,
            30.00
        );

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('from', $data);
        $this->assertArrayHasKey('to', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('totals', $data);
        $this->assertEquals('2024-06-01', $data['from']);
        $this->assertEquals('2024-06-30', $data['to']);

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertNull($row['party_id']);
        $this->assertEquals('All Haris', $row['party_name']);
        $this->assertEquals('HARI', $row['role']);
        $this->assertEqualsWithDelta(100.0, $row['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(20.0, $row['period_movement'], 0.01); // 50 - 30
        $this->assertEqualsWithDelta(120.0, $row['closing_balance'], 0.01);

        $totals = $data['totals'];
        $this->assertEqualsWithDelta(100.0, $totals['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(20.0, $totals['period_movement'], 0.01);
        $this->assertEqualsWithDelta(120.0, $totals['closing_balance'], 0.01);

        // Totals match sum of rows
        $sumOpening = array_sum(array_column($data['rows'], 'opening_balance'));
        $sumMovement = array_sum(array_column($data['rows'], 'period_movement'));
        $sumClosing = array_sum(array_column($data['rows'], 'closing_balance'));
        $this->assertEqualsWithDelta($totals['opening_balance'], $sumOpening, 0.01);
        $this->assertEqualsWithDelta($totals['period_movement'], $sumMovement, 0.01);
        $this->assertEqualsWithDelta($totals['closing_balance'], $sumClosing, 0.01);
    }

    public function test_party_summary_role_filter_returns_only_that_role(): void
    {
        $this->createPostingGroupWithPartyEntry(
            '2024-06-10',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            10.00,
            0
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-06-10',
            $this->partyControlLandlord->id,
            $this->landlordParty->id,
            $this->project->id,
            20.00,
            0
        );

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30&role=HARI');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertEquals('HARI', $data['rows'][0]['role']);
        $this->assertEqualsWithDelta(10.0, $data['rows'][0]['period_movement'], 0.01);
    }

    public function test_party_summary_project_filter_includes_only_that_project(): void
    {
        $otherProject = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Other Project',
            'status' => 'ACTIVE',
        ]);

        $this->createPostingGroupWithPartyEntry(
            '2024-06-10',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            100.00,
            0
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-06-10',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $otherProject->id,
            50.00,
            0
        );

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30&project_id=' . $this->project->id);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertEqualsWithDelta(100.0, $data['rows'][0]['period_movement'], 0.01);
    }

    public function test_party_summary_crop_cycle_filter_includes_only_that_crop_cycle(): void
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
            'posting_date' => '2024-06-10',
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
            'posting_date' => '2024-06-10',
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
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30&crop_cycle_id=' . $this->cropCycle->id);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertEqualsWithDelta(100.0, $data['rows'][0]['period_movement'], 0.01);
    }

    public function test_party_summary_validates_missing_from_to_returns_422(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?to=2024-06-30')
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01')
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-05-01')
            ->assertStatus(422);
    }

    public function test_party_summary_uses_only_party_control_accounts(): void
    {
        $service = app(PartySummaryService::class);
        $this->createPostingGroupWithPartyEntry(
            '2024-06-10',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            25.00,
            0
        );

        $result = $service->getSummary(
            $this->tenant->id,
            '2024-06-01',
            '2024-06-30',
            null,
            null,
            null
        );

        $this->assertCount(1, $result['rows']);
        $this->assertEqualsWithDelta(25.0, $result['rows'][0]['period_movement'], 0.01);
        // Service only queries PARTY_CONTROL_* account IDs; no legacy ADVANCE_*/DUE_FROM_*/PAYABLE_*
    }

    public function test_party_summary_correct_when_allocation_rows_missing(): void
    {
        // Posting group + ledger entry only; no allocation row. Report attributes by control account only.
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-10',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->partyControlHari->id,
            'debit_amount' => '75.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertEquals('HARI', $data['rows'][0]['role']);
        $this->assertEqualsWithDelta(75.0, $data['rows'][0]['period_movement'], 0.01);
        $this->assertEqualsWithDelta(75.0, $data['totals']['period_movement'], 0.01);
    }

    public function test_party_summary_no_duplication_when_multiple_allocation_rows_per_posting_group(): void
    {
        // One PG, one LE to PARTY_CONTROL_HARI; two allocation rows (e.g. hari + landlord on same PG).
        // Report groups by account only; LE must be counted once.
        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'SETTLEMENT',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-10',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $this->partyControlHari->id,
            'debit_amount' => '100.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $this->project->id,
            'party_id' => $this->hariParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 100,
        ]);
        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $this->project->id,
            'party_id' => $this->landlordParty->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 50,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['rows']);
        $this->assertEquals('HARI', $data['rows'][0]['role']);
        $this->assertEqualsWithDelta(100.0, $data['rows'][0]['period_movement'], 0.01);
        $this->assertEqualsWithDelta(100.0, $data['totals']['period_movement'], 0.01);
    }

    public function test_party_summary_multiple_parties_and_totals(): void
    {
        $this->createPostingGroupWithPartyEntry(
            '2024-05-01',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            100.00,
            0
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-05-01',
            $this->partyControlLandlord->id,
            $this->landlordParty->id,
            $this->project->id,
            200.00,
            0
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-06-15',
            $this->partyControlHari->id,
            $this->hariParty->id,
            $this->project->id,
            0,
            30.00
        );
        $this->createPostingGroupWithPartyEntry(
            '2024-06-15',
            $this->partyControlLandlord->id,
            $this->landlordParty->id,
            $this->project->id,
            50.00,
            0
        );

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-summary?from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data['rows']);

        $hariRow = collect($data['rows'])->firstWhere('role', 'HARI');
        $landlordRow = collect($data['rows'])->firstWhere('role', 'LANDLORD');
        $this->assertNotNull($hariRow);
        $this->assertNotNull($landlordRow);
        $this->assertNull($hariRow['party_id']);
        $this->assertNull($landlordRow['party_id']);
        $this->assertEqualsWithDelta(100.0, $hariRow['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(-30.0, $hariRow['period_movement'], 0.01);
        $this->assertEqualsWithDelta(70.0, $hariRow['closing_balance'], 0.01);
        $this->assertEqualsWithDelta(200.0, $landlordRow['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(50.0, $landlordRow['period_movement'], 0.01);
        $this->assertEqualsWithDelta(250.0, $landlordRow['closing_balance'], 0.01);

        $this->assertEqualsWithDelta(300.0, $data['totals']['opening_balance'], 0.01);
        $this->assertEqualsWithDelta(20.0, $data['totals']['period_movement'], 0.01);
        $this->assertEqualsWithDelta(320.0, $data['totals']['closing_balance'], 0.01);
    }
}
