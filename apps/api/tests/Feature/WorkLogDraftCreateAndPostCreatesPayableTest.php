<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\Party;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\LabWorkLog;
use App\Models\LabWorkerBalance;
use App\Models\PostingGroup;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class WorkLogDraftCreateAndPostCreatesPayableTest extends TestCase
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

    public function test_create_work_log_draft_and_post_creates_payable(): void
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

        $createWorker = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/workers', [
                'name' => 'Hari Worker',
                'create_party' => true,
            ]);
        $createWorker->assertStatus(201);
        $workerId = $createWorker->json('id');

        $createLog = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/work-logs', [
                'doc_no' => 'WL-001',
                'worker_id' => $workerId,
                'work_date' => '2024-06-15',
                'crop_cycle_id' => $cropCycle->id,
                'project_id' => $project->id,
                'rate_basis' => 'DAILY',
                'units' => 1,
                'rate' => 500,
                'notes' => null,
            ]);
        $createLog->assertStatus(201);
        $logId = $createLog->json('id');
        $this->assertEquals('DRAFT', $createLog->json('status'));

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$logId}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'wl-001-post',
            ]);
        $post->assertStatus(201);
        $this->assertNotNull($post->json('id'));

        $log = LabWorkLog::find($logId);
        $this->assertEquals('POSTED', $log->status);
        $this->assertNotNull($log->posting_group_id);

        $pg = PostingGroup::find($post->json('id'));
        $this->assertNotNull($pg);
        $this->assertEquals('LABOUR_WORK_LOG', $pg->source_type);
        $this->assertEquals($logId, $pg->source_id);

        $entries = LedgerEntry::where('posting_group_id', $pg->id)->with('account')->get();
        $this->assertCount(2, $entries);
        $drLabour = $entries->first(function ($e) {
            return $e->account->code === 'LABOUR_EXPENSE' && (float) $e->debit_amount > 0;
        });
        $crWages = $entries->first(function ($e) {
            return $e->account->code === 'WAGES_PAYABLE' && (float) $e->credit_amount > 0;
        });
        $this->assertNotNull($drLabour);
        $this->assertNotNull($crWages);
        $this->assertEquals(500, (float) $drLabour->debit_amount);
        $this->assertEquals(500, (float) $crWages->credit_amount);

        $balance = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $workerId)->first();
        $this->assertNotNull($balance);
        $this->assertEquals(500, (float) $balance->payable_balance);
    }
}
