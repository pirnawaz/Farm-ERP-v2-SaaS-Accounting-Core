<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestLine;
use App\Models\HarvestShareLine;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStockMovement;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineWorkLog;
use App\Models\MachineryCharge;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\Machinery\MachineryChargeService;
use App\Services\Machinery\MachineryPostingService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4F.1 — System-level duplicate workflow prevention and valid-path regression.
 *
 * @see \App\Services\DuplicateWorkflowGuard
 */
class Phase4f1DuplicateWorkflowSystemTest extends TestCase
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

    private function seedWip(Tenant $tenant, CropCycle $cropCycle, float $amount = 500.0): void
    {
        $cropWip = Account::where('tenant_id', $tenant->id)->where('code', 'CROP_WIP')->firstOrFail();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->firstOrFail();
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => Str::uuid()->toString(),
            'posting_date' => '2024-05-01',
            'idempotency_key' => 'wip-p4f1-'.uniqid(),
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cropWip->id,
            'debit_amount' => (string) $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cash->id,
            'debit_amount' => 0,
            'credit_amount' => (string) $amount,
            'currency_code' => 'GBP',
        ]);
    }

    public function test_field_job_post_blocked_when_machine_line_sources_posted_machinery_charge(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-MC', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'CC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $landlordParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']]);
        $projectParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'Pty', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $projectParty->id, 'crop_cycle_id' => $cc->id,
            'name' => 'Proj', 'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-P4F1', 'name' => 'Tractor', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id, 'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id, 'effective_from' => '2024-01-01', 'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR, 'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40, 'is_active' => true,
        ]);

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-P4F1-'.substr(uniqid(), -6),
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'work_date' => '2024-06-15',
            'meter_start' => 0,
            'meter_end' => 5,
            'usage_qty' => 5,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        app(MachineryPostingService::class)->postWorkLog($workLog->id, $tenant->id, '2024-06-15');
        $workLog->refresh();

        $chargeService = new MachineryChargeService;
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            '2024-06-15',
            '2024-06-15',
            MachineWorkLog::POOL_SCOPE_SHARED
        );

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];
        $this->withHeaders($h)->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'mc-p4f1-'.uniqid(),
        ])->assertStatus(201);

        $charge->refresh();
        $this->assertTrue($charge->isPosted());

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 2,
            'source_charge_id' => $charge->id,
        ])->assertStatus(201);

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-mc-dup-'.uniqid(),
        ]);
        $fail->assertStatus(422);
        $fail->assertJsonValidationErrors(['duplicate_workflow']);
        $this->assertStringContainsString(
            'Machinery Charge',
            (string) json_encode($fail->json('errors.duplicate_workflow'))
        );

        $this->assertEquals(
            0,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );
    }

    public function test_harvest_post_succeeds_when_in_kind_machine_share_links_source_field_job(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-OK', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Grain', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc);
        $machine = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-OK', 'name' => 'T', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id, 'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id, 'effective_from' => '2024-01-01', 'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR, 'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40, 'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id, 'usage_qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'fj-ok-'.uniqid(),
        ])->assertStatus(201);

        $storeMachine = InvStore::create([
            'tenant_id' => $tenant->id, 'name' => 'Mach bin', 'type' => 'MAIN', 'is_active' => true,
        ]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-15', 'status' => 'DRAFT',
        ]);
        $hLine = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 100, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine->id,
            'store_id' => $storeMachine->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 10,
            'sort_order' => 1,
            'source_field_job_id' => $jobId,
        ]);

        $postHarvest = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'hv-ok-'.uniqid(),
        ]);
        $postHarvest->assertStatus(200);
        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'HARVEST')->where('source_id', $harvest->id)->count()
        );
    }

    public function test_lab_work_log_post_blocked_after_posted_field_job_labour_same_day(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-LAB', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'labour');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'U', 'name' => 'U']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Cat']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Item', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'St', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'CC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'Pr', 'status' => 'ACTIVE',
        ]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id, 'doc_no' => 'GRN-'.uniqid(), 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id,
            'qty' => 10, 'unit_cost' => 10, 'line_total' => 100,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01', 'idempotency_key' => 'grn-'.uniqid(),
            ])->assertStatus(201);

        $worker = LabWorker::create([
            'tenant_id' => $tenant->id, 'name' => 'W1', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY',
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $store->id, 'item_id' => $item->id, 'qty' => 1,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $worker->id,
            'units' => 1,
            'rate' => 50,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'fj-lab-'.uniqid(),
        ])->assertStatus(201);

        $wl = $this->withHeaders($h)->postJson('/api/v1/labour/work-logs', [
            'worker_id' => $worker->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'work_date' => '2024-06-15',
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 100,
        ]);
        $wl->assertStatus(201);
        $wlId = $wl->json('id');

        $fail = $this->withHeaders($h)->postJson("/api/v1/labour/work-logs/{$wlId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'lw-dup-'.uniqid(),
        ]);
        $fail->assertStatus(422);
        $fail->assertJsonValidationErrors(['duplicate_workflow']);
        $this->assertStringContainsString(
            'Field Job',
            (string) json_encode($fail->json('errors.duplicate_workflow'))
        );

        $this->assertEquals(
            0,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'LABOUR_WORK_LOG')->where('source_id', $wlId)->count()
        );
    }

    public function test_lab_work_log_post_blocked_when_in_kind_labour_harvest_already_posted(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-LH', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'labour');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Grain', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc);
        $worker = LabWorker::create([
            'tenant_id' => $tenant->id, 'name' => 'W2', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY',
        ]);
        $storeLab = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Lab', 'type' => 'MAIN', 'is_active' => true]);

        $harvest = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-15', 'status' => 'DRAFT',
        ]);
        $hLine = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 100, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest->id,
            'harvest_line_id' => $hLine->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_LABOUR,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'worker_id' => $worker->id,
            'store_id' => $storeLab->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 5,
            'sort_order' => 1,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest->id}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'hv-lab-'.uniqid(),
        ])->assertStatus(200);

        $wl = $this->withHeaders($h)->postJson('/api/v1/labour/work-logs', [
            'worker_id' => $worker->id,
            'crop_cycle_id' => $cc->id,
            'project_id' => $project->id,
            'work_date' => '2024-06-15',
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 100,
        ]);
        $wl->assertStatus(201);
        $wlId = $wl->json('id');

        $fail = $this->withHeaders($h)->postJson("/api/v1/labour/work-logs/{$wlId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'lw-hvblk-'.uniqid(),
        ]);
        $fail->assertStatus(422);
        $fail->assertJsonValidationErrors(['duplicate_workflow']);
        $this->assertStringContainsString(
            'harvest',
            strtolower((string) json_encode($fail->json('errors.duplicate_workflow')))
        );
    }

    public function test_second_harvest_post_blocked_when_share_reuses_machinery_charge_from_posted_harvest(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-CH2', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Grain', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'H', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $this->seedWip($tenant, $cc);
        $machine1 = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-CH1', 'name' => 'T1', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);
        $machine2 = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-CH2', 'name' => 'T2', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);

        $charge = MachineryCharge::create([
            'tenant_id' => $tenant->id,
            'charge_no' => 'MCH-TEST-'.uniqid(),
            'status' => MachineryCharge::STATUS_POSTED,
            'landlord_party_id' => Party::create(['tenant_id' => $tenant->id, 'name' => 'LL2', 'party_types' => ['LANDLORD']])->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
            'charge_date' => '2024-06-10',
            'posting_date' => '2024-06-10',
            'posted_at' => now(),
            'total_amount' => '100.00',
        ]);

        $storeM = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'M', 'type' => 'MAIN', 'is_active' => true]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $harvest1 = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-15', 'status' => 'DRAFT',
        ]);
        $line1 = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest1->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 100, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest1->id,
            'harvest_line_id' => $line1->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine1->id,
            'store_id' => $storeM->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 10,
            'sort_order' => 1,
            'source_machinery_charge_id' => $charge->id,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest1->id}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'hv1-'.uniqid(),
        ])->assertStatus(200);

        $harvest2 = Harvest::create([
            'tenant_id' => $tenant->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
            'harvest_date' => '2024-06-16', 'status' => 'DRAFT',
        ]);
        $line2 = HarvestLine::create([
            'tenant_id' => $tenant->id, 'harvest_id' => $harvest2->id, 'inventory_item_id' => $item->id,
            'store_id' => $store->id, 'quantity' => 50, 'uom' => 'BAG',
        ]);
        HarvestShareLine::create([
            'tenant_id' => $tenant->id,
            'harvest_id' => $harvest2->id,
            'harvest_line_id' => $line2->id,
            'recipient_role' => HarvestShareLine::RECIPIENT_MACHINE,
            'settlement_mode' => HarvestShareLine::SETTLEMENT_IN_KIND,
            'machine_id' => $machine2->id,
            'store_id' => $storeM->id,
            'inventory_item_id' => $item->id,
            'share_basis' => HarvestShareLine::BASIS_FIXED_QTY,
            'share_value' => 5,
            'sort_order' => 1,
            'source_machinery_charge_id' => $charge->id,
        ]);

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/harvests/{$harvest2->id}/post", [
            'posting_date' => '2024-06-16', 'idempotency_key' => 'hv2-'.uniqid(),
        ]);
        $fail->assertStatus(422);
        $fail->assertJsonValidationErrors(['duplicate_workflow']);
        $this->assertStringContainsString(
            'machinery charge',
            strtolower((string) json_encode($fail->json('errors.duplicate_workflow')))
        );

        $this->assertEquals(
            0,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'HARVEST')->where('source_id', $harvest2->id)->count()
        );
    }

    public function test_field_job_post_idempotency_does_not_duplicate_inventory_movements_or_posting_groups(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-INV', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'U', 'name' => 'U']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Cat']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Item', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'St', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'CC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'Pr', 'status' => 'ACTIVE',
        ]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id, 'doc_no' => 'GRN-'.uniqid(), 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id,
            'qty' => 10, 'unit_cost' => 10, 'line_total' => 100,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01', 'idempotency_key' => 'grn-'.uniqid(),
            ])->assertStatus(201);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $store->id, 'item_id' => $item->id, 'qty' => 2,
        ])->assertStatus(201);

        $idem = 'idem-inv-'.uniqid();
        $p1 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => $idem,
        ]);
        $p1->assertStatus(201);
        $pgId = $p1->json('id');

        $p2 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => $idem,
        ]);
        $p2->assertStatus(201);
        $this->assertEquals($pgId, $p2->json('id'));

        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );

        $moveCount = InvStockMovement::where('tenant_id', $tenant->id)
            ->where('source_type', 'field_job')
            ->where('source_id', $jobId)
            ->count();
        $this->assertSame(1, $moveCount);
    }

    public function test_machinery_charge_generate_blocked_when_work_log_already_captured_by_posted_field_job(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P4F1-GEN', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');
        $this->enableModule($tenant, 'machinery');

        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'CC', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $landlordParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']]);
        $projectParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'Pty', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $projectParty->id, 'crop_cycle_id' => $cc->id,
            'name' => 'Proj', 'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id, 'code' => 'TR-GEN', 'name' => 'Tr', 'machine_type' => 'Tractor',
            'ownership_type' => 'Owned', 'status' => 'Active', 'meter_unit' => 'HOURS', 'opening_meter' => 0,
        ]);
        MachineRateCard::create([
            'tenant_id' => $tenant->id, 'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id, 'effective_from' => '2024-01-01', 'effective_to' => null,
            'rate_unit' => MachineRateCard::RATE_UNIT_HOUR, 'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
            'base_rate' => 40, 'is_active' => true,
        ]);

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-GEN-'.substr(uniqid(), -6),
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'work_date' => '2024-06-15',
            'meter_start' => 0,
            'meter_end' => 4,
            'usage_qty' => 4,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        app(MachineryPostingService::class)->postWorkLog($workLog->id, $tenant->id, '2024-06-15');
        $workLog->refresh();

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15', 'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 4,
            'source_work_log_id' => $workLog->id,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15', 'idempotency_key' => 'fj-gen-'.uniqid(),
        ])->assertStatus(201);

        $gen = $this->withHeaders($h)->postJson('/api/v1/machinery/charges/generate', [
            'project_id' => $project->id,
            'landlord_party_id' => $landlordParty->id,
            'from' => '2024-06-15',
            'to' => '2024-06-15',
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);
        $gen->assertStatus(422);
        $this->assertStringContainsString('Field Job', (string) $gen->getContent());
    }
}
