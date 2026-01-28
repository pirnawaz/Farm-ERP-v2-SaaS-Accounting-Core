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
use App\Models\MachineWorkLogCostLine;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryWorkLogPostingTest extends TestCase
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

    /**
     * Creates tenant, crop cycle, project, machine, and a DRAFT work log (usage-only, no cost lines).
     * Returns MachineWorkLog.
     */
    private function createWorkLogUsageOnly(CropCycle $cropCycle, Project $project, Machine $machine): MachineWorkLog
    {
        $tenant = $cropCycle->tenant_id;
        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant,
            'work_log_no' => 'MWL-000001',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => '2024-06-15',
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            'notes' => null,
        ]);
        return $workLog;
    }

    public function test_post_work_log_creates_posting_group_allocation_row_usage_only(): void
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
            'name' => 'Landlord',
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

        $workLog = $this->createWorkLogUsageOnly($cropCycle, $project, $machine);
        $expectedUsageQty = 6.0;

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'mwl-post-1',
            ]);
        $post->assertStatus(201);

        $workLog->refresh();
        $this->assertEquals(MachineWorkLog::STATUS_POSTED, $workLog->status);
        $this->assertNotNull($workLog->posting_group_id);

        $pg = $post->json('posting_group');
        $this->assertNotNull($pg);
        $pgId = $pg['id'];
        $this->assertEquals('MACHINE_WORK_LOG', $pg['source_type']);
        $this->assertEquals($workLog->id, $pg['source_id']);

        // Assert exactly one AllocationRow with MACHINERY_USAGE
        $allocationRows = AllocationRow::where('posting_group_id', $pgId)->get();
        $this->assertCount(1, $allocationRows);
        
        $allocation = $allocationRows->first();
        $this->assertEquals('MACHINERY_USAGE', $allocation->allocation_type);
        $this->assertNull($allocation->amount);
        $this->assertEqualsWithDelta($expectedUsageQty, (float) $allocation->quantity, 0.01);
        $this->assertEquals('HOURS', $allocation->unit);
        $this->assertEquals($workLog->project_id, $allocation->project_id);

        // Assert zero ledger entries
        $entries = LedgerEntry::where('posting_group_id', $pgId)->get();
        $this->assertCount(0, $entries);
    }

    public function test_post_twice_with_same_idempotency_key_returns_same_posting_group(): void
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
            'name' => 'Landlord',
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
        $workLog = $this->createWorkLogUsageOnly($cropCycle, $project, $machine);

        $key = 'idem-mwl-1';
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $key,
            ]);
        $r1->assertStatus(201);
        $pgId1 = $r1->json('posting_group')['id'];

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $key,
            ]);
        $r2->assertStatus(201);
        $pgId2 = $r2->json('posting_group')['id'];

        $this->assertEquals($pgId1, $pgId2);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('idempotency_key', $key)->count());
    }

    public function test_reverse_posted_work_log_creates_reversal_and_nets_quantity_to_zero(): void
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
            'name' => 'Landlord',
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
        $workLog = $this->createWorkLogUsageOnly($cropCycle, $project, $machine);
        $expectedUsageQty = 6.0;

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'mwl-rev',
            ]);

        $rev = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/reverse", [
                'posting_date' => '2024-06-16',
                'reason' => 'Wrong entry',
            ]);
        $rev->assertStatus(201);

        $workLog->refresh();
        $this->assertEquals(MachineWorkLog::STATUS_REVERSED, $workLog->status);
        $this->assertNotNull($workLog->reversal_posting_group_id);

        $reversalPg = PostingGroup::where('reversal_of_posting_group_id', $workLog->posting_group_id)->first();
        $this->assertNotNull($reversalPg);

        // Assert total quantity nets to zero when combined
        $origAllocs = AllocationRow::where('posting_group_id', $workLog->posting_group_id)->get();
        $revAllocs = AllocationRow::where('posting_group_id', $reversalPg->id)->get();
        $origQtySum = $origAllocs->sum(fn ($r) => (float) ($r->quantity ?? 0));
        $revQtySum = $revAllocs->sum(fn ($r) => (float) ($r->quantity ?? 0));
        $totalQty = $origQtySum + $revQtySum;
        $this->assertEqualsWithDelta(0.0, $totalQty, 0.01);
        $this->assertEqualsWithDelta($expectedUsageQty, $origQtySum, 0.01);
        $this->assertEqualsWithDelta(-$expectedUsageQty, $revQtySum, 0.01);

        // Assert zero ledger entries in both posting groups
        $origEntries = LedgerEntry::where('posting_group_id', $workLog->posting_group_id)->get();
        $revEntries = LedgerEntry::where('posting_group_id', $reversalPg->id)->get();
        $this->assertCount(0, $origEntries);
        $this->assertCount(0, $revEntries);
    }

    public function test_post_fails_when_crop_cycle_is_closed(): void
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
            'name' => 'Landlord',
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
        $workLog = $this->createWorkLogUsageOnly($cropCycle, $project, $machine);

        $cropCycle->update(['status' => 'CLOSED']);

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'mwl-closed',
            ]);
        $this->assertTrue($post->status() >= 400);

        $workLog->refresh();
        $this->assertEquals(MachineWorkLog::STATUS_DRAFT, $workLog->status);
        $this->assertNull($workLog->posting_group_id);

        $pg = PostingGroup::where('tenant_id', $tenant->id)
            ->where('source_type', 'MACHINE_WORK_LOG')
            ->where('source_id', $workLog->id)
            ->first();
        $this->assertNull($pg);
    }
}
