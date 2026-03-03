<?php

namespace Tests\Feature\Machinery;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineryService;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Account;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

/**
 * Machinery Service operational layer tests (Definition of Done).
 * Covers: create draft → update draft → POST creates PostingGroup, AllocationRow, 2 LedgerEntries
 * → REVERSE creates reversal PG and marks service reversed; posting date validation; closed cycle blocks posting.
 */
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

    public function test_create_draft_update_draft_post_creates_posting_group_allocation_row_two_ledger_entries_reverse_creates_reversal_pg_and_marks_service_reversed(): void
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
        $this->assertEquals(MachineryService::STATUS_DRAFT, $service->status);

        $updateRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/v1/machinery/machinery-services/{$service->id}", [
                'quantity' => 12.0,
            ]);
        $updateRes->assertStatus(200);
        $service->refresh();
        $this->assertEqualsWithDelta(12.0, (float) $service->quantity, 0.01);

        $postRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $postRes->assertStatus(201);

        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_POSTED, $service->status);
        $this->assertNotNull($service->posting_group_id);

        $pg = PostingGroup::where('id', $service->posting_group_id)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals('MACHINERY_SERVICE', $pg->source_type);
        $this->assertEquals($service->id, $pg->source_id);

        $allocationRows = AllocationRow::where('posting_group_id', $pg->id)->get();
        $this->assertCount(1, $allocationRows);
        $this->assertEquals('MACHINERY_SERVICE', $allocationRows->first()->allocation_type);

        $ledgerEntries = LedgerEntry::with('account')->where('posting_group_id', $pg->id)->get();
        $this->assertCount(2, $ledgerEntries);
        $expShared = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_SHARED')->firstOrFail();
        $incomeAccount = Account::where('tenant_id', $tenant->id)->where('code', 'MACHINERY_SERVICE_INCOME')->firstOrFail();
        $debitEntry = $ledgerEntries->first(fn ($e) => (float) $e->debit_amount > 0);
        $creditEntry = $ledgerEntries->first(fn ($e) => (float) $e->credit_amount > 0);
        $this->assertNotNull($debitEntry);
        $this->assertNotNull($creditEntry);
        $this->assertEquals($expShared->id, $debitEntry->account_id, 'Debit should be to EXP_SHARED');
        $this->assertEquals($incomeAccount->id, $creditEntry->account_id, 'Credit should be to MACHINERY_SERVICE_INCOME');

        $reverseRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/reverse", [
                'posting_date' => '2024-06-15',
                'reason' => 'DoD test',
            ]);
        $reverseRes->assertStatus(201);

        $service->refresh();
        $this->assertEquals(MachineryService::STATUS_REVERSED, $service->status);
        $this->assertNotNull($service->reversal_posting_group_id);
        $reversalPg = PostingGroup::where('id', $service->reversal_posting_group_id)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotNull($reversalPg->reversal_of_posting_group_id);
        $this->assertEquals($pg->id, $reversalPg->reversal_of_posting_group_id);
    }

    public function test_posting_date_outside_crop_cycle_fails(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-06-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-05-01',
            ]);
        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Posting date is before crop cycle start date.');
    }

    public function test_closed_crop_cycle_blocks_posting(): void
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
            'status' => 'CLOSED',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $res->assertStatus(422);
        $res->assertJsonStructure(['message']);
    }

    public function test_posting_landlord_only_debits_exp_landlord_only_credits_machinery_income(): void
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
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $service = $this->createDraftMachineryService($tenant, $cropCycle, $project, $machine, MachineryService::ALLOCATION_SCOPE_LANDLORD_ONLY, 5.0, 20.00);

        $postRes = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/machinery-services/{$service->id}/post", [
                'posting_date' => '2024-06-15',
            ]);
        $postRes->assertStatus(201);

        $service->refresh();
        $pg = PostingGroup::where('id', $service->posting_group_id)->where('tenant_id', $tenant->id)->firstOrFail();
        $ledgerEntries = LedgerEntry::with('account')->where('posting_group_id', $pg->id)->get();
        $this->assertCount(2, $ledgerEntries);
        $expLandlordOnly = Account::where('tenant_id', $tenant->id)->where('code', 'EXP_LANDLORD_ONLY')->firstOrFail();
        $incomeAccount = Account::where('tenant_id', $tenant->id)->where('code', 'MACHINERY_SERVICE_INCOME')->firstOrFail();
        $debitEntry = $ledgerEntries->first(fn ($e) => (float) $e->debit_amount > 0);
        $creditEntry = $ledgerEntries->first(fn ($e) => (float) $e->credit_amount > 0);
        $this->assertEquals($expLandlordOnly->id, $debitEntry->account_id);
        $this->assertEquals($incomeAccount->id, $creditEntry->account_id);
        $allocationRows = AllocationRow::where('posting_group_id', $pg->id)->get();
        $this->assertCount(1, $allocationRows);
        $this->assertEquals('LANDLORD_ONLY', $allocationRows->first()->allocation_scope);
    }
}
