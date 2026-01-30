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
use App\Models\Account;
use App\Services\PartyAccountService;
use App\Services\PartyLedgerService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class PartyLedgerReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Project $project;
    private Account $partyControlHari;

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
        $this->partyControlHari = $partyAccountService->getPartyControlAccount($this->tenant->id, $this->hariParty->id);
        $this->assertEquals('PARTY_CONTROL_HARI', $this->partyControlHari->code);
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

    private function createPostingGroupWithLedgerEntry(string $postingDate, float $debit, float $credit): PostingGroup
    {
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
            'account_id' => $this->partyControlHari->id,
            'debit_amount' => (string) $debit,
            'credit_amount' => (string) $credit,
            'currency_code' => 'GBP',
        ]);
        return $pg;
    }

    public function test_party_ledger_returns_opening_period_rows_and_closing_balance(): void
    {
        // Opening: one entry before from (2024-05-15) Dr 100
        $this->createPostingGroupWithLedgerEntry('2024-05-15', 100.00, 0);

        // Period: two entries in June
        $this->createPostingGroupWithLedgerEntry('2024-06-01', 50.00, 0);
        $this->createPostingGroupWithLedgerEntry('2024-06-15', 0, 30.00);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?party_id=' . $this->hariParty->id . '&from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('opening_balance', $data);
        $this->assertArrayHasKey('closing_balance', $data);
        $this->assertArrayHasKey('rows', $data);

        // Opening = 100 (only entry before 2024-06-01)
        $this->assertEqualsWithDelta(100.0, $data['opening_balance'], 0.01);

        // Two period rows
        $this->assertCount(2, $data['rows']);

        // First row: 2024-06-01 Dr 50 → running = 100 + 50 = 150
        $this->assertEquals('2024-06-01', $data['rows'][0]['posting_date']);
        $this->assertEqualsWithDelta(50.0, $data['rows'][0]['debit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $data['rows'][0]['credit'], 0.01);
        $this->assertEqualsWithDelta(150.0, $data['rows'][0]['running_balance'], 0.01);

        // Second row: 2024-06-15 Cr 30 → running = 150 - 30 = 120
        $this->assertEquals('2024-06-15', $data['rows'][1]['posting_date']);
        $this->assertEqualsWithDelta(0.0, $data['rows'][1]['debit'], 0.01);
        $this->assertEqualsWithDelta(30.0, $data['rows'][1]['credit'], 0.01);
        $this->assertEqualsWithDelta(120.0, $data['rows'][1]['running_balance'], 0.01);

        // Closing = last running balance
        $this->assertEqualsWithDelta(120.0, $data['closing_balance'], 0.01);
    }

    public function test_party_ledger_uses_posting_date_semantics(): void
    {
        $this->createPostingGroupWithLedgerEntry('2024-06-01', 10.00, 0);
        $this->createPostingGroupWithLedgerEntry('2024-06-30', 0, 5.00);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?party_id=' . $this->hariParty->id . '&from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();

        foreach ($data['rows'] as $row) {
            $this->assertGreaterThanOrEqual('2024-06-01', $row['posting_date']);
            $this->assertLessThanOrEqual('2024-06-30', $row['posting_date']);
        }
    }

    public function test_party_ledger_uses_only_party_control_account(): void
    {
        $service = app(PartyLedgerService::class);
        $this->createPostingGroupWithLedgerEntry('2024-06-10', 25.00, 0);

        $result = $service->getLedger(
            $this->tenant->id,
            $this->partyControlHari->id,
            '2024-06-01',
            '2024-06-30',
            null,
            null
        );

        $this->assertCount(1, $result['rows']);
        $this->assertEqualsWithDelta(25.0, $result['rows'][0]['debit'], 0.01);
        $this->assertEqualsWithDelta(25.0, $result['closing_balance'], 0.01);
        // No legacy account codes are used; service queries by account_id (PARTY_CONTROL_HARI only)
    }

    public function test_party_ledger_validates_required_params(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?from=2024-06-01&to=2024-06-30')
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?party_id=' . $this->hariParty->id . '&to=2024-06-30')
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?party_id=' . $this->hariParty->id . '&from=2024-06-01&to=2024-05-01')
            ->assertStatus(422);
    }

    public function test_party_ledger_returns_empty_rows_when_no_entries_in_period(): void
    {
        $this->createPostingGroupWithLedgerEntry('2024-05-01', 200.00, 0);

        $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/party-ledger?party_id=' . $this->hariParty->id . '&from=2024-06-01&to=2024-06-30');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEqualsWithDelta(200.0, $data['opening_balance'], 0.01);
        $this->assertCount(0, $data['rows']);
        $this->assertEqualsWithDelta(200.0, $data['closing_balance'], 0.01);
    }
}
