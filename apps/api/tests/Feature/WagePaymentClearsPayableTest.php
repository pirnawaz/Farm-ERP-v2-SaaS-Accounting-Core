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
use App\Models\Payment;
use App\Models\LedgerEntry;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class WagePaymentClearsPayableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        (new ModulesSeeder)->run();
        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    public function test_wage_payment_clears_lab_worker_payable(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['labour', 'treasury_payments']);

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

        $hariParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari Worker',
            'party_types' => ['HARI'],
        ]);
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari Worker',
            'worker_type' => 'HARI',
            'rate_basis' => 'DAILY',
            'party_id' => $hariParty->id,
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
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'wl-1-post',
            ])
            ->assertStatus(201);

        $balanceBefore = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertEquals(500, (float) $balanceBefore->payable_balance);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hariParty->id,
            'direction' => 'OUT',
            'purpose' => 'WAGES',
            'amount' => 500.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $postResp = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-wages-1',
                'crop_cycle_id' => $cropCycle->id,
            ]);

        $postResp->assertStatus(201);

        $payment->refresh();
        $this->assertEquals('POSTED', $payment->status);

        $pgId = $postResp->json('id');
        $wagesEntry = LedgerEntry::where('posting_group_id', $pgId)->with('account')->get()
            ->first(fn ($e) => $e->account->code === 'WAGES_PAYABLE' && (float) $e->debit_amount > 0);
        $this->assertNotNull($wagesEntry);
        $this->assertEquals(500, (float) $wagesEntry->debit_amount);

        $balanceAfter = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertNotNull($balanceAfter);
        $this->assertEquals(0, (float) $balanceAfter->payable_balance);
    }
}
