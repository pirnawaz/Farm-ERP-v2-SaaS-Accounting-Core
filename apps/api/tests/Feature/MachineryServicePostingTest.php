<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineryService;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryServicePostingTest extends TestCase
{
    use RefreshDatabase;

    private function enableMachinery(Tenant $tenant): void
    {
        $m = Module::where('key', 'machinery')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function createDraftMachineryService(
        Tenant $tenant,
        CropCycle $cropCycle,
        Project $project,
        Machine $machine,
        string $allocationScope = MachineryService::ALLOCATION_SCOPE_SHARED,
        float $quantity = 10.0,
        float $baseRate = 25.00
    ): MachineryService {
        $rateCard = MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => $baseRate,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => false,
            'is_active' => true,
        ]);

        return MachineryService::create([
            'tenant_id' => $tenant->id,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'rate_card_id' => $rateCard->id,
            'quantity' => (string) $quantity,
            'allocation_scope' => $allocationScope,
            'status' => MachineryService::STATUS_DRAFT,
        ]);
    }

    public function test_post_creates_posting_group(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, MachineryService::ALLOCATION_SCOPE_SHARED, 10.0, 30.00);
        $postingDate = '2024-06-15';

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'machinery-service-post-1',
            ]);
        $response->assertStatus(201);

        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_POSTED, $service->status);
        $this->assertNotNull($service->posting_group_id);
        $this->assertEqualsWithDelta(10.0 * 30.00, (float) $service->amount, 0.01);

        $pg = $response->json('posting_group');
        $this->assertNotNull($pg);
        $this->assertEquals('MACHINERY_SERVICE', $pg['source_type']);
        $this->assertEquals($service->id, $pg['source_id']);
    }

    public function test_allocation_row_has_correct_allocation_scope(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        foreach ([MachineryService::ALLOCATION_SCOPE_SHARED, MachineryService::ALLOCATION_SCOPE_HARI_ONLY] as $scope) {
            $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, $scope, 5.0, 20.00);
            $this->withHeader('X-Tenant-Id', $tenant->id)
                ->withHeader('X-User-Role', 'accountant')
                ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                    'posting_date' => '2024-06-15',
                ])
                ->assertStatus(201);

            $row = AllocationRow::where('posting_group_id', $service->fresh()->posting_group_id)->first();
            $this->assertNotNull($row);
            $this->assertEquals('MACHINERY_SERVICE', $row->allocation_type);
            $this->assertEquals($scope, $row->allocation_scope);
            $this->assertEquals($project->id, $row->project_id);
        }
    }

    public function test_ledger_is_balanced(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, MachineryService::ALLOCATION_SCOPE_SHARED, 8.0, 15.00);
        $expectedAmount = 8.0 * 15.00;

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $response->assertStatus(201);

        $pgId = $response->json('posting_group.id');
        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $this->assertCount(2, $entries);

        $debitTotal = 0;
        $creditTotal = 0;
        foreach ($entries as $entry) {
            $debitTotal += (float) $entry->debit_amount;
            $creditTotal += (float) $entry->credit_amount;
        }
        $this->assertEqualsWithDelta($expectedAmount, $debitTotal, 0.01);
        $this->assertEqualsWithDelta($expectedAmount, $creditTotal, 0.01);
        $this->assertEqualsWithDelta($debitTotal, $creditTotal, 0.01);
    }

    public function test_settlement_preview_includes_machinery_service_cost(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, MachineryService::ALLOCATION_SCOPE_SHARED, 4.0, 50.00);
        $expectedExpense = 4.0 * 50.00; // 200.00

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-06-15',
            ])
            ->assertStatus(201);

        $settlementService = app(SettlementService::class);
        $preview = $settlementService->previewSettlement($project->id, $tenant->id, '2024-06-30');

        $totalExpenses = (float) $preview['total_expenses'];
        $poolProfit = (float) $preview['pool_profit'];
        $this->assertGreaterThanOrEqual($expectedExpense, $totalExpenses, 'Settlement preview total_expenses should include machinery service cost');
        $this->assertEqualsWithDelta(-$expectedExpense, $poolProfit, 0.01, 'With no revenue, pool_profit should equal minus machinery service expense');
    }

    public function test_post_then_reverse_nets_to_zero_in_allocations_and_settlement(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-01',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $quantity = 6.0;
        $baseRate = 40.00;
        $expectedAmount = $quantity * $baseRate;
        $postingDate = '2024-06-15';
        $upToDate = '2024-06-30';

        $settlementService = app(SettlementService::class);
        $baselinePreview = $settlementService->previewSettlement($project->id, $tenant->id, $upToDate);
        $baselineExpenses = (float) $baselinePreview['total_expenses'];
        $baselinePoolProfit = (float) $baselinePreview['pool_profit'];

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, MachineryService::ALLOCATION_SCOPE_SHARED, $quantity, $baseRate);

        $postResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => $postingDate,
            ]);
        $postResponse->assertStatus(201);

        $service->refresh();
        $originalPgId = $service->posting_group_id;
        $this->assertNotNull($originalPgId);

        $afterPostPreview = $settlementService->previewSettlement($project->id, $tenant->id, $upToDate);
        $this->assertEqualsWithDelta($baselineExpenses + $expectedAmount, (float) $afterPostPreview['total_expenses'], 0.01);
        $this->assertEqualsWithDelta($baselinePoolProfit - $expectedAmount, (float) $afterPostPreview['pool_profit'], 0.01);

        $reverseResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/reverse", [
                'posting_date' => $postingDate,
                'reason' => 'Net-to-zero test',
            ]);
        $reverseResponse->assertStatus(201);

        $reversalPgId = $reverseResponse->json('posting_group.id');
        $this->assertNotNull($reversalPgId);

        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_REVERSED, $service->status);
        $this->assertEquals($reversalPgId, $service->reversal_posting_group_id);

        $originalPg = PostingGroup::where('id', $originalPgId)->where('tenant_id', $tenant->id)->firstOrFail();
        $reversalPg = PostingGroup::where('id', $reversalPgId)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotNull($originalPg);
        $this->assertNotNull($reversalPg);

        $originalRows = AllocationRow::where('posting_group_id', $originalPgId)->where('allocation_type', 'MACHINERY_SERVICE')->get();
        $reversalRows = AllocationRow::where('posting_group_id', $reversalPgId)->where('allocation_type', 'MACHINERY_SERVICE')->get();
        $this->assertCount(1, $originalRows, 'Original posting group has one MACHINERY_SERVICE allocation row');
        $this->assertCount(1, $reversalRows, 'Reversal posting group has one MACHINERY_SERVICE allocation row');

        $originalAmount = (float) $originalRows->first()->amount;
        $reversalAmount = (float) $reversalRows->first()->amount;
        $this->assertEqualsWithDelta($expectedAmount, $originalAmount, 0.01, 'Original allocation row amount should be +X');
        $this->assertEqualsWithDelta(-$expectedAmount, $reversalAmount, 0.01, 'Reversal allocation row amount should be -X');
        $this->assertEqualsWithDelta(0, $originalAmount + $reversalAmount, 0.01, 'Sum of allocation amounts (original + reversal) should net to zero');

        $originalEntries = LedgerEntry::where('posting_group_id', $originalPgId)->get();
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPgId)->get();
        $totalDebits = $originalEntries->sum(fn ($e) => (float) $e->debit_amount) + $reversalEntries->sum(fn ($e) => (float) $e->debit_amount);
        $totalCredits = $originalEntries->sum(fn ($e) => (float) $e->credit_amount) + $reversalEntries->sum(fn ($e) => (float) $e->credit_amount);
        $this->assertEqualsWithDelta($totalDebits, $totalCredits, 0.01, 'Ledger should be balanced across original and reversal');

        $afterReversePreview = $settlementService->previewSettlement($project->id, $tenant->id, $upToDate);
        $this->assertEqualsWithDelta($baselineExpenses, (float) $afterReversePreview['total_expenses'], 0.01,
            'Settlement total_expenses should return to baseline after reversal');
        $this->assertEqualsWithDelta($baselinePoolProfit, (float) $afterReversePreview['pool_profit'], 0.01,
            'Settlement pool_profit should return to baseline after reversal');
    }
}
