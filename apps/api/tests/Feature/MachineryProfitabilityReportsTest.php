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
use App\Models\MachineMaintenanceJob;
use App\Models\MachineMaintenanceJobLine;
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvIssue;
use App\Models\InvIssueLine;
use App\Services\TenantContext;
use App\Services\Machinery\MachineryPostingService;
use App\Services\Machinery\MachineryChargeService;
use App\Services\Machinery\MachineryChargePostingService;
use App\Services\Machinery\MachineMaintenancePostingService;
use App\Services\LabourPostingService;
use App\Services\InventoryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryProfitabilityReportsTest extends TestCase
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

    private function enableInventory(Tenant $tenant): void
    {
        $m = Module::where('key', 'inventory')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

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

    public function test_profitability_report_combines_usage_charges_and_costs(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);
        $this->enableInventory($tenant);
        $this->enableLabour($tenant);

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

        // 1. Create and post work log (usage)
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
        $machineryPostingService = new MachineryPostingService();
        $machineryPostingService->postWorkLog($workLog->id, $tenant->id, $postingDate);
        $workLog->refresh();

        // 2. Create rate card and generate/post charge
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

        $chargeService = new MachineryChargeService();
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            $postingDate,
            $postingDate,
            MachineWorkLog::POOL_SCOPE_SHARED
        );

        $chargePostingService = new MachineryChargePostingService();
        $chargePostingService->postCharge($charge->id, $tenant->id, $postingDate);
        $charge->refresh();

        $expectedChargesTotal = 6.0 * 50.00; // usage_qty * rate

        // 3. Create and post inventory issue with machine_id (fuel cost)
        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'L', 'name' => 'Liter']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fuel']);
        $fuelItem = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Diesel',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        // First create GRN to have stock
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $fuelItem->id,
            'qty' => 100,
            'unit_cost' => 1.50,
            'line_total' => 150,
        ]);

        $inventoryPostingService = new InventoryPostingService();
        $inventoryPostingService->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-1');

        // Create and post issue with machine_id
        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'doc_date' => $postingDate,
            'status' => 'DRAFT',
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $fuelItem->id,
            'qty' => 20,
        ]);
        $inventoryPostingService->postIssue($issue->id, $tenant->id, $postingDate, 'issue-1');
        $expectedFuelCost = 20 * 1.50; // 30.00

        // 4. Create and post labour log with machine_id
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-001',
            'worker_type' => 'CASUAL',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
        ]);

        $labWorkLog = LabWorkLog::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'WL-001',
            'worker_id' => $worker->id,
            'work_date' => $postingDate,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 100,
            'amount' => 100,
            'status' => 'DRAFT',
        ]);

        $labourPostingService = new LabourPostingService();
        $labourPostingService->postWorkLog($labWorkLog->id, $tenant->id, $postingDate);
        $expectedLabourCost = 100.00;

        // 5. Create and post maintenance job
        $maintenanceJob = MachineMaintenanceJob::create([
            'tenant_id' => $tenant->id,
            'job_no' => 'MMJ-000001',
            'status' => MachineMaintenanceJob::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'maintenance_type_id' => null,
            'vendor_party_id' => null,
            'job_date' => $postingDate,
            'notes' => 'Oil change',
            'total_amount' => 150.00,
        ]);
        MachineMaintenanceJobLine::create([
            'tenant_id' => $tenant->id,
            'job_id' => $maintenanceJob->id,
            'description' => 'Oil change',
            'amount' => 150.00,
        ]);

        $maintenancePostingService = new MachineMaintenancePostingService();
        $maintenancePostingService->postJob($maintenanceJob->id, $tenant->id, $postingDate);
        $expectedMaintenanceCost = 150.00;

        // Total expected costs: fuel (30) + labour (100) + maintenance (150) = 280
        $expectedTotalCosts = $expectedFuelCost + $expectedLabourCost + $expectedMaintenanceCost;

        // Call profitability report
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/machinery/reports/profitability', [
                'from' => $postingDate,
                'to' => $postingDate,
            ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertCount(1, $data); // One machine

        $row = $data[0];
        $this->assertEquals($machine->id, $row['machine_id']);
        $this->assertEquals('TRK-01', $row['machine_code']);
        $this->assertEquals('Tractor 1', $row['machine_name']);
        $this->assertEquals('HOUR', $row['unit']); // HOURS mapped to HOUR

        // Assert usage
        $this->assertEqualsWithDelta(6.0, (float) $row['usage_qty'], 0.01);

        // Assert charges
        $this->assertEqualsWithDelta($expectedChargesTotal, (float) $row['charges_total'], 0.01);

        // Assert costs
        $this->assertEqualsWithDelta($expectedTotalCosts, (float) $row['costs_total'], 0.01);

        // Assert margin
        $expectedMargin = $expectedChargesTotal - $expectedTotalCosts;
        $this->assertEqualsWithDelta($expectedMargin, (float) $row['margin'], 0.01);

        // Assert per-unit values
        $expectedCostPerUnit = $expectedTotalCosts / 6.0;
        $expectedChargePerUnit = $expectedChargesTotal / 6.0;
        $expectedMarginPerUnit = $expectedMargin / 6.0;

        $this->assertNotNull($row['cost_per_unit']);
        $this->assertEqualsWithDelta($expectedCostPerUnit, (float) $row['cost_per_unit'], 0.01);

        $this->assertNotNull($row['charge_per_unit']);
        $this->assertEqualsWithDelta($expectedChargePerUnit, (float) $row['charge_per_unit'], 0.01);

        $this->assertNotNull($row['margin_per_unit']);
        $this->assertEqualsWithDelta($expectedMarginPerUnit, (float) $row['margin_per_unit'], 0.01);
    }

    public function test_charges_by_machine_report(): void
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
        $machineryPostingService = new MachineryPostingService();
        $machineryPostingService->postWorkLog($workLog->id, $tenant->id, $postingDate);

        // Create rate card and generate/post charge
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

        $chargeService = new MachineryChargeService();
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            $postingDate,
            $postingDate,
            MachineWorkLog::POOL_SCOPE_SHARED
        );

        $chargePostingService = new MachineryChargePostingService();
        $chargePostingService->postCharge($charge->id, $tenant->id, $postingDate);

        $expectedUsageQty = 6.0;
        $expectedChargesTotal = 6.0 * 50.00;

        // Call charges-by-machine report
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/machinery/reports/charges-by-machine', [
                'from' => $postingDate,
                'to' => $postingDate,
            ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertCount(1, $data);

        $row = $data[0];
        $this->assertEquals($machine->id, $row['machine_id']);
        $this->assertEquals('TRK-01', $row['machine_code']);
        $this->assertEquals('Tractor 1', $row['machine_name']);
        $this->assertEquals('HOUR', $row['unit']);
        $this->assertEqualsWithDelta($expectedUsageQty, (float) $row['usage_qty'], 0.01);
        $this->assertEqualsWithDelta($expectedChargesTotal, (float) $row['charges_total'], 0.01);
    }

    public function test_costs_by_machine_report(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableMachinery($tenant);
        $this->enableInventory($tenant);
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

        // Create and post inventory issue with machine_id
        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'L', 'name' => 'Liter']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fuel']);
        $fuelItem = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Diesel',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Store',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $fuelItem->id,
            'qty' => 100,
            'unit_cost' => 1.50,
            'line_total' => 150,
        ]);

        $inventoryPostingService = new InventoryPostingService();
        $inventoryPostingService->postGRN($grn->id, $tenant->id, '2024-06-01', 'grn-1');

        $issue = InvIssue::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'ISS-1',
            'store_id' => $store->id,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'doc_date' => $postingDate,
            'status' => 'DRAFT',
        ]);
        InvIssueLine::create([
            'tenant_id' => $tenant->id,
            'issue_id' => $issue->id,
            'item_id' => $fuelItem->id,
            'qty' => 20,
        ]);
        $inventoryPostingService->postIssue($issue->id, $tenant->id, $postingDate, 'issue-1');
        $expectedFuelCost = 20 * 1.50; // 30.00

        // Create and post labour log with machine_id
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id,
            'name' => 'Worker 1',
            'worker_no' => 'W-001',
            'worker_type' => 'CASUAL',
            'rate_basis' => 'DAILY',
            'default_rate' => 100,
            'is_active' => true,
        ]);

        $labWorkLog = LabWorkLog::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'WL-001',
            'worker_id' => $worker->id,
            'work_date' => $postingDate,
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'machine_id' => $machine->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 100,
            'amount' => 100,
            'status' => 'DRAFT',
        ]);

        $labourPostingService = new LabourPostingService();
        $labourPostingService->postWorkLog($labWorkLog->id, $tenant->id, $postingDate);
        $expectedLabourCost = 100.00;

        // Create and post maintenance job
        $maintenanceJob = MachineMaintenanceJob::create([
            'tenant_id' => $tenant->id,
            'job_no' => 'MMJ-000001',
            'status' => MachineMaintenanceJob::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'maintenance_type_id' => null,
            'vendor_party_id' => null,
            'job_date' => $postingDate,
            'notes' => 'Oil change',
            'total_amount' => 150.00,
        ]);
        MachineMaintenanceJobLine::create([
            'tenant_id' => $tenant->id,
            'job_id' => $maintenanceJob->id,
            'description' => 'Oil change',
            'amount' => 150.00,
        ]);

        $maintenancePostingService = new MachineMaintenancePostingService();
        $maintenancePostingService->postJob($maintenanceJob->id, $tenant->id, $postingDate);
        $expectedMaintenanceCost = 150.00;

        $expectedTotalCosts = $expectedFuelCost + $expectedLabourCost + $expectedMaintenanceCost;

        // Call costs-by-machine report
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/machinery/reports/costs-by-machine', [
                'from' => $postingDate,
                'to' => $postingDate,
            ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertIsArray($data);
        $this->assertCount(1, $data);

        $row = $data[0];
        $this->assertEquals($machine->id, $row['machine_id']);
        $this->assertEquals('TRK-01', $row['machine_code']);
        $this->assertEquals('Tractor 1', $row['machine_name']);
        $this->assertEqualsWithDelta($expectedTotalCosts, (float) $row['costs_total'], 0.01);
        $this->assertIsArray($row['breakdown']);
        $this->assertGreaterThan(0, count($row['breakdown']));
    }
}
