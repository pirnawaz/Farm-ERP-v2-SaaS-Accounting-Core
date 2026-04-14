<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\CropActivityType;
use App\Models\LabWorker;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\Machine;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;

class ManualLegacyCreateOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function enableModule(Tenant $tenant, string $key): void
    {
        $m = Module::where('key', $key)->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function seedTenantWithCoreRefs(): array
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();

        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CropCycle::create([
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
            'crop_cycle_id' => $cc->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        return [$tenant, $cc, $party, $project];
    }

    public function test_crop_activity_store_requires_manual_override_flag(): void
    {
        [$tenant, $cc, $party, $project] = $this->seedTenantWithCoreRefs();
        $this->enableModule($tenant, 'crop_ops');

        $type = CropActivityType::create(['tenant_id' => $tenant->id, 'name' => 'Sowing', 'is_active' => true]);

        $noAck = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/crop-ops/activities', [
                'doc_no' => 'ACT-1',
                'activity_type_id' => $type->id,
                'activity_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
            ]);
        $noAck->assertStatus(422);
        $this->assertEquals('MANUAL_EXCEPTION_ACK_REQUIRED', $noAck->json('error_code'));

        $ack = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/crop-ops/activities', [
                'manual_exception_acknowledged' => true,
                'doc_no' => 'ACT-2',
                'activity_type_id' => $type->id,
                'activity_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
            ]);
        $ack->assertStatus(201);
    }

    public function test_labour_work_log_store_requires_manual_override_flag(): void
    {
        [$tenant, $cc, $party, $project] = $this->seedTenantWithCoreRefs();
        $this->enableModule($tenant, 'labour');

        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'W1', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);

        $noAck = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/work-logs', [
                'doc_no' => 'WL-1',
                'worker_id' => $worker->id,
                'work_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'rate_basis' => 'DAILY',
                'units' => 1,
                'rate' => 100,
            ]);
        $noAck->assertStatus(422);
        $this->assertEquals('MANUAL_EXCEPTION_ACK_REQUIRED', $noAck->json('error_code'));

        $ack = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/labour/work-logs', [
                'manual_exception_acknowledged' => true,
                'doc_no' => 'WL-2',
                'worker_id' => $worker->id,
                'work_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'rate_basis' => 'DAILY',
                'units' => 1,
                'rate' => 100,
            ]);
        $ack->assertStatus(201);
    }

    public function test_inventory_issue_store_requires_manual_override_flag(): void
    {
        [$tenant, $cc, $party, $project] = $this->seedTenantWithCoreRefs();
        $this->enableModule($tenant, 'inventory');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Inputs']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main Store', 'type' => 'MAIN', 'is_active' => true]);

        $noAck = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/issues', [
                'doc_no' => 'ISS-1',
                'store_id' => $store->id,
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'doc_date' => '2024-06-15',
                'lines' => [['item_id' => $item->id, 'qty' => 1]],
                'allocation_mode' => 'HARI_ONLY',
                'hari_id' => $party->id,
            ]);
        $noAck->assertStatus(422);
        $this->assertEquals('MANUAL_EXCEPTION_ACK_REQUIRED', $noAck->json('error_code'));

        $ack = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/issues', [
                'manual_exception_acknowledged' => true,
                'doc_no' => 'ISS-2',
                'store_id' => $store->id,
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'doc_date' => '2024-06-15',
                'lines' => [['item_id' => $item->id, 'qty' => 1]],
                'allocation_mode' => 'HARI_ONLY',
                'hari_id' => $party->id,
            ]);
        $ack->assertStatus(201);
    }

    public function test_machinery_work_log_store_requires_manual_override_flag(): void
    {
        [$tenant, $cc, $party, $project] = $this->seedTenantWithCoreRefs();
        $this->enableModule($tenant, 'machinery');

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

        $noAck = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/work-logs', [
                'machine_id' => $machine->id,
                'project_id' => $project->id,
                'work_date' => '2024-06-15',
                'meter_start' => 10,
                'meter_end' => 11,
            ]);
        $noAck->assertStatus(422);
        $this->assertEquals('MANUAL_EXCEPTION_ACK_REQUIRED', $noAck->json('error_code'));

        $ack = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/work-logs', [
                'manual_exception_acknowledged' => true,
                'machine_id' => $machine->id,
                'project_id' => $project->id,
                'work_date' => '2024-06-15',
                'meter_start' => 10,
                'meter_end' => 11,
            ]);
        $ack->assertStatus(201);
    }
}

