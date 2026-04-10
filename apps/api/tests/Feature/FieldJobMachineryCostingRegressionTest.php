<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\FieldJob;
use App\Models\FieldJobMachine;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
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
use Tests\TestCase;

/**
 * Phase 2D — Regression tests for automatic machinery costing on FieldJob posting
 * and compatibility with legacy machinery postings.
 */
class FieldJobMachineryCostingRegressionTest extends TestCase
{
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

    /**
     * @return array{
     *   tenant: Tenant,
     *   project: Project,
     *   store: InvStore,
     *   item: InvItem,
     *   worker: LabWorker,
     *   machine: Machine,
     *   machineRateCard: MachineRateCard|null,
     *   headers: array<string, string>
     * }
     */
    private function seedTenantWithStockProjectWorkerMachine(bool $createMachineRateCard = true, float $machineHourRate = 50.0): array
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FJ2D-'.uniqid(), 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'U', 'name' => 'U']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Cat']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Item', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Store', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id, 'doc_no' => 'GRN-'.uniqid(), 'store_id' => $store->id,
            'doc_date' => '2024-06-01', 'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id,
            'qty' => 10, 'unit_cost' => 50, 'line_total' => 500,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'grn-'.uniqid(),
            ]);

        $worker = LabWorker::create([
            'tenant_id' => $tenant->id, 'name' => 'W', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'M-'.substr(uniqid(), -6),
            'name' => 'Tractor',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
        ]);

        $rateCard = null;
        if ($createMachineRateCard) {
            $rateCard = MachineRateCard::create([
                'tenant_id' => $tenant->id,
                'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
                'machine_id' => $machine->id,
                'machine_type' => null,
                'activity_type_id' => null,
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'rate_unit' => MachineRateCard::RATE_UNIT_HOUR,
                'pricing_model' => MachineRateCard::PRICING_MODEL_FIXED,
                'base_rate' => $machineHourRate,
                'cost_plus_percent' => null,
                'includes_fuel' => true,
                'includes_operator' => true,
                'includes_maintenance' => true,
                'is_active' => true,
            ]);
        }

        $headers = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        return [
            'tenant' => $tenant,
            'project' => $project,
            'store' => $store,
            'item' => $item,
            'worker' => $worker,
            'machine' => $machine,
            'machineRateCard' => $rateCard,
            'headers' => $headers,
        ];
    }

    public function test_field_job_posting_with_machine_line_creates_usage_allocation_and_financial_machine_cost(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 1,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 10,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-2d-usage-fin-'.uniqid(),
        ])->assertStatus(201);

        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->firstOrFail();
        $income = Account::where('tenant_id', $tenantId)->where('code', 'MACHINERY_SERVICE_INCOME')->firstOrFail();
        $expShared = Account::where('tenant_id', $tenantId)->where('code', 'EXP_SHARED')->firstOrFail();

        $expectedMach = round(2 * 50.0, 2);
        $machDr = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $expShared->id)->sum('debit_amount');
        $machCr = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $income->id)->sum('credit_amount');
        $this->assertEquals($expectedMach, round((float) $machDr, 2));
        $this->assertEquals($expectedMach, round((float) $machCr, 2));

        $this->assertEquals(1, AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->count());
        $usage = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->first();
        $this->assertNull($usage->amount);
        $this->assertEquals('2.00', (string) $usage->quantity);
        $this->assertEquals('HR', $usage->unit);

        $this->assertEquals(1, AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_SERVICE')->count());
        $fin = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_SERVICE')->first();
        $this->assertEquals($expectedMach, round((float) $fin->amount, 2));
        $this->assertEquals('HOUR', $fin->unit);
    }

    public function test_field_job_posting_with_machine_line_snapshots_rate_and_amount(): void
    {
        $rate = 77.50;
        $s = $this->seedTenantWithStockProjectWorkerMachine(true, $rate);
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2.5,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-snap-'.uniqid(),
        ])->assertStatus(201);

        $line = FieldJobMachine::where('field_job_id', $jobId)->firstOrFail();
        $this->assertEquals(FieldJobMachine::PRICING_BASIS_RATE_CARD, $line->pricing_basis);
        $this->assertNotNull($s['machineRateCard']);
        $this->assertEquals($s['machineRateCard']->id, $line->rate_card_id);
        $this->assertEquals(round(2.5 * $rate, 2), round((float) $line->amount, 2));
        $this->assertEqualsWithDelta((float) $rate, (float) $line->rate_snapshot, 0.0001);
    }

    public function test_field_job_repost_does_not_duplicate_machinery_financial_effect(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 1,
        ]);

        $idem = 'fj-idem-mach-'.uniqid();
        $r1 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => $idem,
        ]);
        $r1->assertStatus(201);
        $pgId = $r1->json('id');

        $exp = Account::where('tenant_id', $tenantId)->where('code', 'EXP_SHARED')->firstOrFail();
        $inc = Account::where('tenant_id', $tenantId)->where('code', 'MACHINERY_SERVICE_INCOME')->firstOrFail();
        $countMachLedger = function () use ($pgId, $exp, $inc) {
            return LedgerEntry::where('posting_group_id', $pgId)
                ->whereIn('account_id', [$exp->id, $inc->id])
                ->count();
        };
        $this->assertEquals(2, $countMachLedger());
        $allocFin = AllocationRow::where('posting_group_id', $pgId)->where('allocation_type', 'MACHINERY_SERVICE')->count();
        $this->assertEquals(1, $allocFin);

        $r2 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => $idem,
        ]);
        $r2->assertStatus(201);
        $this->assertEquals($pgId, $r2->json('id'));
        $this->assertEquals(2, $countMachLedger());
        $this->assertEquals(1, AllocationRow::where('posting_group_id', $pgId)->where('allocation_type', 'MACHINERY_SERVICE')->count());
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count());
    }

    public function test_reversing_field_job_unwinds_machinery_financial_effect(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 1.25,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-rev-mach-'.uniqid(),
        ])->assertStatus(201);

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->firstOrFail();
        $exp = Account::where('tenant_id', $tenantId)->where('code', 'EXP_SHARED')->firstOrFail();
        $inc = Account::where('tenant_id', $tenantId)->where('code', 'MACHINERY_SERVICE_INCOME')->firstOrFail();

        $rev = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/reverse", [
            'posting_date' => '2024-06-20',
            'reason' => '2d regression reverse',
        ]);
        $rev->assertStatus(201);
        $revPgId = $rev->json('id');

        $netBalance = function (string $accountId) use ($pg, $revPgId) {
            $rows = LedgerEntry::whereIn('posting_group_id', [$pg->id, $revPgId])
                ->where('account_id', $accountId)
                ->get();

            return $rows->sum(fn ($e) => (float) $e->debit_amount - (float) $e->credit_amount);
        };

        $this->assertEqualsWithDelta(0.0, $netBalance($exp->id), 0.02);
        $this->assertEqualsWithDelta(0.0, $netBalance($inc->id), 0.02);

        $projId = $s['project']->id;
        $machSvcSum = AllocationRow::where('project_id', $projId)
            ->where('allocation_type', 'MACHINERY_SERVICE')
            ->whereIn('posting_group_id', [$pg->id, $revPgId])
            ->get()
            ->sum(fn ($r) => (float) ($r->amount ?? 0));
        $this->assertEqualsWithDelta(0.0, $machSvcSum, 0.02);

        $usageQtySum = AllocationRow::where('project_id', $projId)
            ->where('allocation_type', 'MACHINERY_USAGE')
            ->whereIn('posting_group_id', [$pg->id, $revPgId])
            ->get()
            ->sum(fn ($r) => (float) ($r->quantity ?? 0));
        $this->assertEqualsWithDelta(0.0, $usageQtySum, 0.02);
    }

    public function test_field_job_machine_line_without_billable_rate_creates_usage_only_or_fails_according_to_current_rule_choice(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine(false);
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
        ]);

        $post = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-no-rate-'.uniqid(),
        ]);

        $this->assertNotEquals(201, $post->status(), 'Posting must fail when no rate card and no manual amount on the machine line.');
        $this->assertEquals(
            0,
            PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );

        $fjModel = FieldJob::find($jobId);
        $this->assertEquals('DRAFT', $fjModel->status);
    }

    public function test_existing_manual_machinery_charge_posting_still_works(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T-MC-2d', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'machinery');

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
            'code' => 'TRK-2d',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        MachineRateCard::create([
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

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-2D-001',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => '2024-06-15',
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        $postingDate = '2024-06-15';
        app(MachineryPostingService::class)->postWorkLog($workLog->id, $tenant->id, $postingDate);
        $workLog->refresh();

        $chargeService = new MachineryChargeService;
        $charge = $chargeService->generateDraftChargeForProject(
            $tenant->id,
            $project->id,
            $landlordParty->id,
            $postingDate,
            $postingDate,
            MachineWorkLog::POOL_SCOPE_SHARED
        );

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/charges/{$charge->id}/post", [
                'posting_date' => $postingDate,
                'idempotency_key' => 'charge-2d-legacy-'.uniqid(),
            ]);
        $post->assertStatus(201);

        $charge->refresh();
        $this->assertEquals(MachineryCharge::STATUS_POSTED, $charge->status);
        $this->assertNotNull($charge->posting_group_id);

        $pgId = $charge->posting_group_id;
        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'MACHINERY_CHARGE')->where('source_id', $charge->id)->count()
        );
        $this->assertEquals(1, AllocationRow::where('posting_group_id', $pgId)->where('allocation_type', 'MACHINERY_CHARGE')->count());
        $this->assertEquals(2, LedgerEntry::where('posting_group_id', $pgId)->count());
    }

    public function test_existing_machine_work_log_posting_still_works(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T-MWL-2d', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'machinery');

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
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TRK-MWL2d',
            'name' => 'Tractor 1',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-2D-002',
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => '2024-06-15',
            'meter_start' => 100,
            'meter_end' => 106,
            'usage_qty' => 6,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'mwl-2d-'.uniqid(),
            ]);
        $post->assertStatus(201);

        $workLog->refresh();
        $this->assertEquals(MachineWorkLog::STATUS_POSTED, $workLog->status);
        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'MACHINE_WORK_LOG')->where('source_id', $workLog->id)->first();
        $this->assertNotNull($pg);
        $this->assertGreaterThanOrEqual(1, AllocationRow::where('posting_group_id', $pg->id)->count());
    }

    public function test_project_financial_impact_from_field_job_includes_inputs_labour_and_machinery(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine(true, 40.0);
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;
        $projectId = $s['project']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $projectId,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 1,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 100,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-proj-impact-'.uniqid(),
        ])->assertStatus(201);

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->firstOrFail();

        $inputsPool = AllocationRow::where('posting_group_id', $pg->id)
            ->where('allocation_type', 'POOL_SHARE')
            ->where('project_id', $projectId)
            ->get()
            ->first(fn ($r) => ($r->rule_snapshot['cost_type'] ?? null) === 'inputs');
        $labourPool = AllocationRow::where('posting_group_id', $pg->id)
            ->where('allocation_type', 'POOL_SHARE')
            ->where('project_id', $projectId)
            ->get()
            ->first(fn ($r) => ($r->rule_snapshot['cost_type'] ?? null) === 'labour');
        $machFin = AllocationRow::where('posting_group_id', $pg->id)
            ->where('allocation_type', 'MACHINERY_SERVICE')
            ->where('project_id', $projectId)
            ->first();

        $this->assertNotNull($inputsPool);
        $this->assertNotNull($labourPool);
        $this->assertNotNull($machFin);

        $this->assertEquals(50.0, round((float) $inputsPool->amount, 2));
        $this->assertEquals(100.0, round((float) $labourPool->amount, 2));
        $this->assertEquals(80.0, round((float) $machFin->amount, 2));

        $usage = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->first();
        $this->assertNotNull($usage);
        $this->assertNull($usage->amount);
    }
}
