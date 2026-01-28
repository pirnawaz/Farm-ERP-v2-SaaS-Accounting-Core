<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\Machine;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class LabourWorkLogPostingTest extends TestCase
{
    use RefreshDatabase;

    private function enableLabour(Tenant $tenant): void
    {
        $m = Module::where('key', 'labour')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_post_work_log_with_machine_id_propagates_to_allocation_row(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLabour($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-001',
            'worker_type' => 'CASUAL',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
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

        // Create work log with machine_id
        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/work-logs', [
                'doc_no' => 'WL-001',
                'worker_id' => $worker->id,
                'work_date' => '2024-06-15',
                'crop_cycle_id' => $cropCycle->id,
                'project_id' => $project->id,
                'machine_id' => $machine->id,
                'rate_basis' => 'DAILY',
                'units' => 1,
                'rate' => 100,
            ]);
        $create->assertStatus(201);
        $workLogId = $create->json('id');

        // Post work log
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLogId}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'lab-post-1',
            ]);
        $post->assertStatus(201);

        $pgId = $post->json('id');
        $alloc = AllocationRow::where('posting_group_id', $pgId)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($project->id, $alloc->project_id);
        $this->assertEquals($machine->id, $alloc->machine_id);
        $this->assertEquals(100, (float) $alloc->amount);
        $this->assertEquals('POOL_SHARE', $alloc->allocation_type);
    }

    public function test_post_work_log_without_machine_id_has_null_machine_id_in_allocation_row(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLabour($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-002',
            'worker_type' => 'CASUAL',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
        ]);

        // Create work log without machine_id
        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/work-logs', [
                'doc_no' => 'WL-002',
                'worker_id' => $worker->id,
                'work_date' => '2024-06-15',
                'crop_cycle_id' => $cropCycle->id,
                'project_id' => $project->id,
                'rate_basis' => 'DAILY',
                'units' => 1,
                'rate' => 100,
            ]);
        $create->assertStatus(201);
        $workLogId = $create->json('id');

        // Post work log
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLogId}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'lab-post-2',
            ]);
        $post->assertStatus(201);

        $pgId = $post->json('id');
        $alloc = AllocationRow::where('posting_group_id', $pgId)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($project->id, $alloc->project_id);
        $this->assertNull($alloc->machine_id);
        $this->assertEquals(100, (float) $alloc->amount);
        $this->assertEquals('POOL_SHARE', $alloc->allocation_type);
    }
}
