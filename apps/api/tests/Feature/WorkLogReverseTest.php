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

class WorkLogReverseTest extends TestCase
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

    public function test_reverse_work_log_restores_balance_and_marks_reversed(): void
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

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'k1']);

        $rev = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/reverse", ['posting_date' => '2024-06-16', 'reason' => 'Wrong entry']);
        $rev->assertStatus(201);

        $workLog->refresh();
        $this->assertEquals('REVERSED', $workLog->status);

        $balance = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(0, (float) $balance->payable_balance);

        $reversalPg = PostingGroup::where('reversal_of_posting_group_id', $workLog->posting_group_id)->first();
        $this->assertNotNull($reversalPg);
    }
}
