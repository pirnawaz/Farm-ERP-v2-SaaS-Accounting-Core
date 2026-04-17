<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineWorkLog;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\Machinery\MachineryExternalIncomePostingService;
use App\Services\Machinery\MachineryPostingService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5BMachineryProfitCenterTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function enableModules(Tenant $tenant): void
    {
        foreach (['machinery', 'reports', 'projects_crop_cycles'] as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    public function test_internal_charge_posts_project_expense_and_machinery_revenue_and_farm_pnl_neutral(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5B', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'T-1',
            'name' => 'Tractor',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $postingDate = '2024-06-10';
        $farmBefore = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from='.$postingDate.'&to='.$postingDate.'&crop_cycle_id='.$cycle->id
        )->assertOk()->json();
        $netBefore = (float) ($farmBefore['combined']['net_farm_operating_result'] ?? 0);

        $wl = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-P5B-001',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'work_date' => $postingDate,
            'meter_start' => 0,
            'meter_end' => 5,
            'usage_qty' => 5,
            'chargeable' => true,
            'internal_charge_rate' => '10.0000',
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        $svc = app(MachineryPostingService::class);
        $pg = $svc->postWorkLog($wl->id, $tenant->id, $postingDate);
        $wl->refresh();

        $this->assertEquals(MachineWorkLog::STATUS_POSTED, $wl->status);
        $this->assertEquals('50.00', $wl->internal_charge_amount);

        $les = LedgerEntry::where('posting_group_id', $pg->id)->get();
        $this->assertCount(2, $les);
        $sumDr = round((float) $les->sum(fn ($e) => (float) $e->debit_amount), 2);
        $sumCr = round((float) $les->sum(fn ($e) => (float) $e->credit_amount), 2);
        $this->assertEquals(50.0, $sumDr);
        $this->assertEquals(50.0, $sumCr);

        $farmAfter = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from='.$postingDate.'&to='.$postingDate.'&crop_cycle_id='.$cycle->id
        )->assertOk()->json();
        $netAfter = (float) ($farmAfter['combined']['net_farm_operating_result'] ?? 0);
        $this->assertEqualsWithDelta($netBefore, $netAfter, 0.02, 'Internal machinery charge should not change farm net operating result');

        $mProf = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/machinery-profitability?from='.$postingDate.'&to='.$postingDate.'&machine_id='.$machine->id
        )->assertOk()->json();
        $row = collect($mProf)->firstWhere('machine_id', $machine->id);
        $this->assertNotNull($row);
        $this->assertEquals(50.0, (float) $row['revenue']);
    }

    public function test_external_machinery_income_increases_machine_revenue(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5B-E', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $customer = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['CUSTOMER'],
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'H-1',
            'name' => 'Harvester',
            'machine_type' => 'Harvester',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $postingDate = '2024-07-01';
        $svc = app(MachineryExternalIncomePostingService::class);
        $svc->post(
            $tenant->id,
            $machine->id,
            $cycle->id,
            250.00,
            $postingDate,
            ['party_id' => $customer->id, 'memo' => 'Custom combining'],
            'ext-ink-1'
        );

        $mProf = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/machine-profitability?from='.$postingDate.'&to='.$postingDate.'&machine_id='.$machine->id
        )->assertOk()->json();
        $row = collect($mProf)->firstWhere('machine_id', $machine->id);
        $this->assertNotNull($row);
        $this->assertEquals(250.0, (float) $row['revenue']);
    }

    public function test_non_chargeable_work_log_still_has_no_ledger_entries(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5B-L', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'T-2',
            'name' => 'Tractor 2',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $wl = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-P5B-002',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'work_date' => '2024-06-12',
            'meter_start' => 0,
            'meter_end' => 2,
            'usage_qty' => 2,
            'chargeable' => false,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        $svc = app(MachineryPostingService::class);
        $pg = $svc->postWorkLog($wl->id, $tenant->id, '2024-06-12');
        $this->assertCount(0, LedgerEntry::where('posting_group_id', $pg->id)->get());
    }
}
