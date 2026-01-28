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
use App\Models\MachineryChargeLine;
use App\Models\PostingGroup;
use App\Services\TenantContext;
use App\Services\Machinery\MachineryPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class MachineryChargeGenerationTest extends TestCase
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

    private function createPostedWorkLog(CropCycle $cropCycle, Project $project, Machine $machine, string $postingDate, string $poolScope = MachineWorkLog::POOL_SCOPE_SHARED): MachineWorkLog
    {
        $tenant = $cropCycle->tenant_id;
        
        // Generate unique work log number
        $last = MachineWorkLog::where('tenant_id', $tenant)
            ->where('work_log_no', 'like', 'MWL-%')
            ->orderByRaw('LENGTH(work_log_no) DESC, work_log_no DESC')
            ->first();
        $next = 1;
        if ($last && preg_match('/^MWL-(\d+)$/', $last->work_log_no, $m)) {
            $next = (int) $m[1] + 1;
        }
        $workLogNo = 'MWL-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        
        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant,
            'work_log_no' => $workLogNo,
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => $postingDate,
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => $poolScope,
        ]);

        // Post the work log
        $postingService = new MachineryPostingService();
        $postingService->postWorkLog($workLog->id, $tenant, $postingDate);

        return $workLog->fresh();
    }

    public function test_generation_creates_draft_charge_links_work_logs_totals_correct(): void
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
        $postingDate = '2024-06-15';
        $workLog = $this->createPostedWorkLog($cropCycle, $project, $machine, $postingDate);
        $expectedAmount = 6.0 * 50.00; // usage_qty * rate

        // Generate charge
        $generate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/charges/generate', [
                'project_id' => $project->id,
                'landlord_party_id' => $landlordParty->id,
                'from' => $postingDate,
                'to' => $postingDate,
                'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            ]);
        $generate->assertStatus(201);

        $chargeData = $generate->json();
        $this->assertNotNull($chargeData);
        $chargeId = $chargeData['id'];

        $charge = MachineryCharge::find($chargeId);
        $this->assertNotNull($charge);
        $this->assertEquals(MachineryCharge::STATUS_DRAFT, $charge->status);
        $this->assertEquals($landlordParty->id, $charge->landlord_party_id);
        $this->assertEquals($project->id, $charge->project_id);
        $this->assertEquals($cropCycle->id, $charge->crop_cycle_id);
        $this->assertEquals(MachineryCharge::POOL_SCOPE_SHARED, $charge->pool_scope);
        $this->assertEqualsWithDelta($expectedAmount, (float) $charge->total_amount, 0.01);

        // Verify work log is linked
        $workLog->refresh();
        $this->assertEquals($charge->id, $workLog->machinery_charge_id);

        // Verify charge line
        $lines = MachineryChargeLine::where('machinery_charge_id', $charge->id)->get();
        $this->assertCount(1, $lines);
        $line = $lines->first();
        $this->assertEquals($workLog->id, $line->machine_work_log_id);
        $this->assertEqualsWithDelta(6.0, (float) $line->usage_qty, 0.01);
        $this->assertEquals('HOUR', $line->unit);
        $this->assertEqualsWithDelta(50.00, (float) $line->rate, 0.01);
        $this->assertEqualsWithDelta($expectedAmount, (float) $line->amount, 0.01);
        $this->assertEquals($rateCard->id, $line->rate_card_id);
    }

    public function test_generation_fails_when_rate_card_missing(): void
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

        // Create and post work log (no rate card)
        $postingDate = '2024-06-15';
        $workLog = $this->createPostedWorkLog($cropCycle, $project, $machine, $postingDate);

        // Generate charge should fail
        $generate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/charges/generate', [
                'project_id' => $project->id,
                'landlord_party_id' => $landlordParty->id,
                'from' => $postingDate,
                'to' => $postingDate,
                'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            ]);
        $generate->assertStatus(422);
        $this->assertStringContainsString('Rate cards not found', $generate->json('message') ?? '');
    }

    public function test_generation_does_not_include_already_charged_logs(): void
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

        // Create and post two work logs
        $postingDate = '2024-06-15';
        $workLog1 = $this->createPostedWorkLog($cropCycle, $project, $machine, $postingDate);
        $workLog2 = $this->createPostedWorkLog($cropCycle, $project, $machine, $postingDate);

        // Generate first charge
        $generate1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/charges/generate', [
                'project_id' => $project->id,
                'landlord_party_id' => $landlordParty->id,
                'from' => $postingDate,
                'to' => $postingDate,
                'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            ]);
        $generate1->assertStatus(201);
        $charge1 = MachineryCharge::find($generate1->json('id'));
        $this->assertNotNull($charge1);

        // Verify both work logs are linked to first charge
        $workLog1->refresh();
        $workLog2->refresh();
        $this->assertEquals($charge1->id, $workLog1->machinery_charge_id);
        $this->assertEquals($charge1->id, $workLog2->machinery_charge_id);

        // Generate second charge - should not include already charged logs
        $generate2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/machinery/charges/generate', [
                'project_id' => $project->id,
                'landlord_party_id' => $landlordParty->id,
                'from' => $postingDate,
                'to' => $postingDate,
                'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            ]);
        $generate2->assertStatus(422);
        $this->assertStringContainsString('No uncharged posted work logs', $generate2->json('message') ?? '');
    }
}
