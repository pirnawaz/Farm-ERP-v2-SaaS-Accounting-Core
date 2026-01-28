<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Machine;
use App\Models\MachineWorkLog;
use App\Models\MachineRateCard;
use App\Models\MachineryCharge;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use App\Services\Machinery\MachineryPostingService;
use App\Services\Machinery\MachineryChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryChargePostingTest extends TestCase
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

    private function createDraftCharge(Tenant $tenant, CropCycle $cropCycle, Project $project, Party $landlordParty, Machine $machine, string $postingDate): MachineryCharge
    {
        // Create rate card
        $rateCard = MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'machine_type' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
            'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 50.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => true,
            'is_active' => true,
        ]);

        // Create and post work log
        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-000001',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => $postingDate,
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        // Post the work log
        $postingService = new MachineryPostingService();
        $postingService->postWorkLog($workLog->id, $tenant->id, $postingDate);
        $workLog->refresh();

        // Generate charge
        $chargeService = new MachineryChargeService();
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            $postingDate,
            $postingDate,
            MachineWorkLog::POOL_SCOPE_SHARED
        );

        return $charge;
    }

    public function test_post_creates_posting_group_allocation_row_and_balanced_ledger(): void
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
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
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

        $postingDate = '2024-06-15';
        $charge = $this->createDraftCharge($tenant, $cropCycle, $project, $landlordParty, $machine, $postingDate);
        $expectedAmount = 6.0 * 50.00; // usage_qty * rate

        // Post charge
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'charge-post-1',
            ]);
        $post->assertStatus(201);

        $charge->refresh();
        $this->assertEquals(MachineryCharge::STATUS_POSTED, $charge->status);
        $this->assertNotNull($charge->posting_group_id);
        $this->assertNotNull($charge->posting_date);
        $this->assertNotNull($charge->posted_at);

        $pg = $post->json('posting_group');
        $this->assertNotNull($pg);
        $pgId = $pg['id'];
        $this->assertEquals('MACHINERY_CHARGE', $pg['source_type']);
        $this->assertEquals($charge->id, $pg['source_id']);

        // Assert exactly one AllocationRow with MACHINERY_CHARGE
        $allocationRows = AllocationRow::where('posting_group_id', $pgId)->get();
        $this->assertCount(1, $allocationRows);
        
        $allocation = $allocationRows->first();
        $this->assertEquals('MACHINERY_CHARGE', $allocation->allocation_type);
        $this->assertEqualsWithDelta($expectedAmount, (float) $allocation->amount, 0.01);
        $this->assertNull($allocation->quantity);
        $this->assertNull($allocation->unit);
        $this->assertEquals($charge->project_id, $allocation->project_id);
        $this->assertEquals($charge->landlord_party_id, $allocation->party_id);

        // Assert balanced ledger entries
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

    public function test_posting_is_idempotent_with_same_idempotency_key(): void
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
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
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

        $postingDate = '2024-06-15';
        $charge = $this->createDraftCharge($tenant, $cropCycle, $project, $landlordParty, $machine, $postingDate);
        $idempotencyKey = 'charge-post-idempotent';

        // First post
        $post1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => $idempotencyKey,
            ]);
        $post1->assertStatus(201);
        $pg1 = $post1->json('posting_group');
        $pg1Id = $pg1['id'];

        // Second post with same idempotency key
        $post2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => $idempotencyKey,
            ]);
        $post2->assertStatus(201);
        $pg2 = $post2->json('posting_group');
        $pg2Id = $pg2['id'];

        // Should return same posting group
        $this->assertEquals($pg1Id, $pg2Id);

        // Should only have one posting group
        $pgCount = PostingGroup::where('tenant_id', $tenant->id)
            ->where('source_type', 'MACHINERY_CHARGE')
            ->where('source_id', $charge->id)
            ->count();
        $this->assertEquals(1, $pgCount);
    }

    public function test_reverse_creates_reversal_and_nets_allocations_ledger_to_zero(): void
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
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
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

        $postingDate = '2024-06-15';
        $charge = $this->createDraftCharge($tenant, $cropCycle, $project, $landlordParty, $machine, $postingDate);
        $expectedAmount = 6.0 * 50.00;

        // Post charge
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
            ]);
        $post->assertStatus(201);
        $charge->refresh();
        $originalPgId = $charge->posting_group_id;

        // Reverse charge
        $reverse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/reverse", [
                'posting_date' => $postingDate,
                'reason' => 'Test reversal',
            ]);
        $reverse->assertStatus(201);

        $charge->refresh();
        $this->assertEquals(MachineryCharge::STATUS_REVERSED, $charge->status);
        $this->assertNotNull($charge->reversal_posting_group_id);

        $reversalPg = $reverse->json('posting_group');
        $reversalPgId = $reversalPg['id'];

        // Assert allocations net to zero
        $originalAllocations = AllocationRow::where('posting_group_id', $originalPgId)->get();
        $reversalAllocations = AllocationRow::where('posting_group_id', $reversalPgId)->get();
        
        $originalTotal = 0;
        foreach ($originalAllocations as $alloc) {
            $originalTotal += (float) ($alloc->amount ?? 0);
        }
        $reversalTotal = 0;
        foreach ($reversalAllocations as $alloc) {
            $reversalTotal += (float) ($alloc->amount ?? 0);
        }
        $this->assertEqualsWithDelta(0, $originalTotal + $reversalTotal, 0.01);

        // Assert ledger entries net to zero
        $originalEntries = LedgerEntry::where('posting_group_id', $originalPgId)->get();
        $reversalEntries = LedgerEntry::where('posting_group_id', $reversalPgId)->get();

        $originalDebit = 0;
        $originalCredit = 0;
        foreach ($originalEntries as $entry) {
            $originalDebit += (float) $entry->debit_amount;
            $originalCredit += (float) $entry->credit_amount;
        }

        $reversalDebit = 0;
        $reversalCredit = 0;
        foreach ($reversalEntries as $entry) {
            $reversalDebit += (float) $entry->debit_amount;
            $reversalCredit += (float) $entry->credit_amount;
        }

        // Original: Dr expense, Cr liability
        // Reversal: Cr expense, Dr liability
        $this->assertEqualsWithDelta(0, ($originalDebit - $originalCredit) + ($reversalDebit - $reversalCredit), 0.01);
    }

    public function test_posting_fails_when_crop_cycle_closed(): void
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
            'status' => 'CLOSED', // Closed cycle
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
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

        $postingDate = '2024-06-15';
        // Create charge with OPEN cycle first, then close it
        $cropCycle->update(['status' => 'OPEN']);
        $charge = $this->createDraftCharge($tenant, $cropCycle, $project, $landlordParty, $machine, $postingDate);
        $cropCycle->update(['status' => 'CLOSED']);

        // Post should fail
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
            ]);
        $post->assertStatus(500);
        $this->assertStringContainsString('crop cycle is closed', $post->json('message') ?? '');
    }

    public function test_posting_fails_when_date_outside_range(): void
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
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
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

        $postingDate = '2024-06-15';
        $charge = $this->createDraftCharge($tenant, $cropCycle, $project, $landlordParty, $machine, $postingDate);

        // Post with date outside range (before start)
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => '2023-12-31', // Before cycle start
            ]);
        $post->assertStatus(500);
        $this->assertStringContainsString('before crop cycle start date', $post->json('message') ?? '');

        // Post with date outside range (after end)
        $post2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => '2025-01-01', // After cycle end
            ]);
        $post2->assertStatus(500);
        $this->assertStringContainsString('after crop cycle end date', $post2->json('message') ?? '');
    }
}
