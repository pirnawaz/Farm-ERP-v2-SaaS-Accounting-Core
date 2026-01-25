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
use App\Models\LabWorkerBalance;
use App\Models\PostingGroup;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class WorkLogPostIdempotencyTest extends TestCase
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

    public function test_posting_work_log_twice_with_same_idempotency_returns_same_pg_and_no_double_balance(): void
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

        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
        ]);
        LabWorkerBalance::getOrCreate($tenant->id, $worker->id);

        $workLog = LabWorkLog::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'WL-1',
            'worker_id' => $worker->id,
            'work_date' => '2024-06-15',
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 500,
            'amount' => 500,
            'status' => 'DRAFT',
        ]);

        $key = 'idem-wl-1';
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r1->assertStatus(201);
        $pgId1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r2->assertStatus(201);
        $pgId2 = $r2->json('id');

        $this->assertEquals($pgId1, $pgId2);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('idempotency_key', $key)->count());

        $balance = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(500, (float) $balance->payable_balance);
    }
}
