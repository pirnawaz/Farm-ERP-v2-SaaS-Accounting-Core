<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropActivityType;
use App\Models\CropCycle;
use App\Models\FieldJob;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LabWorker;
use App\Models\LabWorkLog;
use App\Models\LabWorkerBalance;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\MachineWorkLog;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Tests\TestCase;

/**
 * Phase 1E — Field Job foundation: CRUD/posting safety and regression against legacy operational postings.
 */
class FieldJobFoundationTest extends TestCase
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
     *   cropCycle: CropCycle,
     *   project: Project,
     *   store: InvStore,
     *   item: InvItem,
     *   worker: LabWorker,
     *   machine: Machine,
     *   headers: array<string, string>
     * }
     */
    private function seedTenantWithStockProjectWorkerMachine(): array
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FJ-'.uniqid(), 'status' => 'active']);
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

        MachineRateCard::create([
            'tenant_id' => $tenant->id,
            'applies_to_mode' => MachineRateCard::APPLIES_TO_MACHINE,
            'machine_id' => $machine->id,
            'machine_type' => null,
            'activity_type_id' => null,
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

        $headers = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        return [
            'tenant' => $tenant,
            'cropCycle' => $cc,
            'project' => $project,
            'store' => $store,
            'item' => $item,
            'worker' => $worker,
            'machine' => $machine,
            'headers' => $headers,
        ];
    }

    public function test_can_create_draft_field_job_with_inputs_labour_and_machines(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 1,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 50,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
        ])->assertStatus(201);

        $show = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}");
        $show->assertStatus(200);
        $this->assertCount(1, $show->json('inputs'));
        $this->assertCount(1, $show->json('labour'));
        $this->assertCount(1, $show->json('machines'));
        $this->assertEquals('DRAFT', $show->json('status'));
    }

    public function test_draft_field_job_can_be_updated(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
            'notes' => 'a',
        ]);
        $jobId = $fj->json('id');

        $up = $this->withHeaders($h)->putJson("/api/v1/crop-ops/field-jobs/{$jobId}", [
            'notes' => 'b',
            'job_date' => '2024-06-16',
        ]);
        $up->assertStatus(200);
        $this->assertEquals('b', $up->json('notes'));
        $this->assertStringStartsWith('2024-06-16', (string) $up->json('job_date'));
    }

    public function test_posted_field_job_cannot_be_updated(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

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

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-u-'.uniqid(),
        ])->assertStatus(201);

        $bad = $this->withHeaders($h)->putJson("/api/v1/crop-ops/field-jobs/{$jobId}", [
            'notes' => 'nope',
        ]);
        $bad->assertStatus(422);
    }

    public function test_posting_field_job_consumes_stock_for_inputs(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $before = InvStockBalance::where('tenant_id', $s['tenant']->id)
            ->where('store_id', $s['store']->id)->where('item_id', $s['item']->id)->first();
        $this->assertEquals(10, (float) $before->qty_on_hand);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 3,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 1,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-stock-'.uniqid(),
        ])->assertStatus(201);

        $after = InvStockBalance::where('tenant_id', $s['tenant']->id)
            ->where('store_id', $s['store']->id)->where('item_id', $s['item']->id)->first();
        $this->assertEquals(7, (float) $after->qty_on_hand);

        $pg = PostingGroup::where('tenant_id', $s['tenant']->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->first();
        $this->assertNotNull($pg);
        $move = InvStockMovement::where('tenant_id', $s['tenant']->id)
            ->where('posting_group_id', $pg->id)->where('movement_type', 'ISSUE')->first();
        $this->assertNotNull($move);
    }

    public function test_posting_field_job_fails_when_stock_is_insufficient(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 100,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 1,
        ]);

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-bad-'.uniqid(),
        ]);
        $this->assertNotEquals(201, $fail->getStatusCode());
        $this->assertStringContainsString('Insufficient', (string) $fail->getContent());
    }

    public function test_posting_field_job_creates_single_posting_group_only_even_if_retried(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

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
            'rate' => 1,
        ]);

        $key = 'idem-fj-'.uniqid();
        $r1 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => $key,
        ]);
        $r1->assertStatus(201);
        $r2 = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => $key,
        ]);
        $r2->assertStatus(201);
        $this->assertEquals($r1->json('id'), $r2->json('id'));
        $this->assertEquals(
            1,
            PostingGroup::where('tenant_id', $s['tenant']->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count()
        );
    }

    public function test_posting_field_job_books_labour_expense_and_worker_payable(): void
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
            'units' => 2,
            'rate' => 75.5,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-gl-'.uniqid(),
        ])->assertStatus(201);

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->first();
        $this->assertNotNull($pg);
        $this->assertEquals('FIELD_JOB', $pg->source_type);
        $this->assertEquals($jobId, $pg->source_id);

        $labourExp = Account::where('tenant_id', $tenantId)->where('code', 'LABOUR_EXPENSE')->first();
        $wagesPay = Account::where('tenant_id', $tenantId)->where('code', 'WAGES_PAYABLE')->first();
        $this->assertNotNull($labourExp);
        $this->assertNotNull($wagesPay);

        $labourDebit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $labourExp->id)->sum('debit_amount');
        $labourCredit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $labourExp->id)->sum('credit_amount');
        $wagesDebit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $wagesPay->id)->sum('debit_amount');
        $wagesCredit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $wagesPay->id)->sum('credit_amount');

        $this->assertEquals(151.0, round((float) $labourDebit, 2));
        $this->assertEquals(0.0, (float) $labourCredit);
        $this->assertEquals(0.0, (float) $wagesDebit);
        $this->assertEquals(151.0, round((float) $wagesCredit, 2));

        $inputsExp = Account::where('tenant_id', $tenantId)->where('code', 'INPUTS_EXPENSE')->first();
        $invInputs = Account::where('tenant_id', $tenantId)->where('code', 'INVENTORY_INPUTS')->first();
        $inDebit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $inputsExp->id)->sum('debit_amount');
        $inCredit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $invInputs->id)->sum('credit_amount');
        $this->assertGreaterThan(0, (float) $inDebit);
        $this->assertEquals($inDebit, $inCredit);
    }

    public function test_posting_field_job_records_machine_usage_and_financial_cost(): void
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
            'rate' => 1,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 4.25,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-mu-'.uniqid(),
        ])->assertStatus(201);

        $pg = PostingGroup::where('tenant_id', $tenantId)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->first();
        $income = Account::where('tenant_id', $tenantId)->where('code', 'MACHINERY_SERVICE_INCOME')->first();
        $expShared = Account::where('tenant_id', $tenantId)->where('code', 'EXP_SHARED')->first();
        $this->assertNotNull($income);
        $this->assertNotNull($expShared);

        $expectedMachineryCost = round(4.25 * 50.0, 2);
        $incomeCredit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $income->id)->sum('credit_amount');
        $expDebit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $expShared->id)->sum('debit_amount');
        $this->assertEquals($expectedMachineryCost, round((float) $incomeCredit, 2));
        $this->assertEquals($expectedMachineryCost, round((float) $expDebit, 2));

        $usage = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->first();
        $this->assertNotNull($usage);
        $this->assertNull($usage->amount);
        $this->assertEquals('4.25', (string) $usage->quantity);
        $this->assertEquals('HR', $usage->unit);

        $fin = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_SERVICE')->first();
        $this->assertNotNull($fin);
        $this->assertEquals($expectedMachineryCost, round((float) $fin->amount, 2));
        $this->assertEquals('HOUR', $fin->unit);
    }

    public function test_reversing_field_job_restores_stock_and_worker_balance(): void
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
            'qty' => 2,
        ]);
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 1,
            'rate' => 40,
        ]);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-rev-'.uniqid(),
        ])->assertStatus(201);

        $this->assertEquals(8, (float) InvStockBalance::where('tenant_id', $tenantId)
            ->where('store_id', $s['store']->id)->where('item_id', $s['item']->id)->first()->qty_on_hand);
        $this->assertEquals(40, (float) LabWorkerBalance::where('tenant_id', $tenantId)->where('worker_id', $s['worker']->id)->first()->payable_balance);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/reverse", [
            'posting_date' => '2024-06-20',
            'reason' => 'test',
        ])->assertStatus(201);

        $job = FieldJob::find($jobId);
        $this->assertEquals('REVERSED', $job->status);
        $this->assertNotNull($job->reversed_at);
        $this->assertNotNull($job->reversal_posting_group_id);

        $this->assertEquals(10, (float) InvStockBalance::where('tenant_id', $tenantId)
            ->where('store_id', $s['store']->id)->where('item_id', $s['item']->id)->first()->qty_on_hand);
        $this->assertEquals(0, (float) LabWorkerBalance::where('tenant_id', $tenantId)->where('worker_id', $s['worker']->id)->first()->payable_balance);
    }

    public function test_field_job_posting_respects_crop_cycle_lock(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

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
            'rate' => 1,
        ]);

        $s['cropCycle']->update(['status' => 'CLOSED']);

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-lock-'.uniqid(),
        ]);
        $fail->assertStatus(422);
    }

    public function test_field_job_posting_rejects_out_of_range_posting_date(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

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
            'rate' => 1,
        ]);

        $fail = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2023-06-15',
            'idempotency_key' => 'fj-date-'.uniqid(),
        ]);
        $this->assertNotEquals(201, $fail->getStatusCode());
        $this->assertStringContainsString('Posting date is before', (string) $fail->getContent());
    }

    public function test_existing_crop_activity_posting_still_works(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Legacy-CA', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'B', 'name' => 'B']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'I', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'S', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'CC', 'start_date' => '2024-01-01', 'end_date' => '2026-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'Pr', 'status' => 'ACTIVE',
        ]);
        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'G-CA', 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 10, 'line_total' => 100]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-01', 'idempotency_key' => 'g-ca']);

        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'W', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);
        $type = CropActivityType::create(['tenant_id' => $tenant->id, 'name' => 'T', 'is_active' => true]);

        $cr = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/crop-ops/activities', [
                'doc_no' => 'ACT-LEG',
                'activity_type_id' => $type->id,
                'activity_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'inputs' => [['store_id' => $store->id, 'item_id' => $item->id, 'qty' => 2]],
                'labour' => [['worker_id' => $worker->id, 'units' => 1, 'rate' => 50]],
            ]);
        $cr->assertStatus(201);
        $activityId = $cr->json('id');

        $key = 'idem-ca-'.uniqid();
        $p1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/crop-ops/activities/{$activityId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $p1->assertStatus(201);
        $p2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/crop-ops/activities/{$activityId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $p2->assertStatus(201);
        $this->assertEquals($p1->json('id'), $p2->json('id'));

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'CROP_ACTIVITY')->where('source_id', $activityId)->first();
        $this->assertNotNull($pg);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'CROP_ACTIVITY')->where('source_id', $activityId)->count());

        $this->assertEquals(8, (float) InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first()->qty_on_hand);
        $this->assertEquals(50, (float) LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first()->payable_balance);
    }

    public function test_existing_lab_work_log_posting_still_works(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Legacy-WL', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'labour');

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $projectParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'L', 'party_types' => ['LANDLORD']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $projectParty->id, 'crop_cycle_id' => $cropCycle->id,
            'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'H', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);
        LabWorkerBalance::getOrCreate($tenant->id, $worker->id);

        $workLog = LabWorkLog::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'WL-LEG',
            'worker_id' => $worker->id,
            'work_date' => '2024-06-15',
            'crop_cycle_id' => $cropCycle->id,
            'project_id' => $project->id,
            'rate_basis' => 'DAILY',
            'units' => 1,
            'rate' => 300,
            'amount' => 300,
            'status' => 'DRAFT',
        ]);

        $key = 'idem-wl-'.uniqid();
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r1->assertStatus(201);
        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/labour/work-logs/{$workLog->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r2->assertStatus(201);
        $this->assertEquals($r1->json('id'), $r2->json('id'));

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'LABOUR_WORK_LOG')->where('source_id', $workLog->id)->first();
        $this->assertNotNull($pg);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'LABOUR_WORK_LOG')->where('source_id', $workLog->id)->count());
        $this->assertEquals(300, (float) LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first()->payable_balance);
    }

    public function test_existing_machine_work_log_posting_still_works(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Legacy-MWL', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'machinery');

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => 'C', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN',
        ]);
        $projectParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'L', 'party_types' => ['LANDLORD']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $projectParty->id, 'crop_cycle_id' => $cropCycle->id,
            'name' => 'P', 'status' => 'ACTIVE',
        ]);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TR-'.uniqid(),
            'name' => 'T',
            'machine_type' => 'Tractor',
            'ownership_type' => 'Owned',
            'status' => 'Active',
            'meter_unit' => 'HOURS',
            'opening_meter' => 0,
        ]);

        $workLog = MachineWorkLog::create([
            'tenant_id' => $tenant->id,
            'work_log_no' => 'MWL-'.substr(uniqid(), -8),
            'status' => MachineWorkLog::STATUS_DRAFT,
            'machine_id' => $machine->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'work_date' => '2024-06-15',
            'meter_start' => 0,
            'meter_end' => 5,
            'usage_qty' => 5,
            'pool_scope' => MachineWorkLog::POOL_SCOPE_SHARED,
        ]);

        $key = 'idem-mwl-'.uniqid();
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $key,
            ]);
        $r1->assertStatus(201);
        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/machinery/work-logs/{$workLog->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $key,
            ]);
        $r2->assertStatus(201);
        $this->assertEquals($r1->json('posting_group.id'), $r2->json('posting_group.id'));

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'MACHINE_WORK_LOG')->where('source_id', $workLog->id)->first();
        $this->assertNotNull($pg);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'MACHINE_WORK_LOG')->where('source_id', $workLog->id)->count());
        $this->assertEquals(0, LedgerEntry::where('posting_group_id', $pg->id)->count());
        $this->assertEquals(1, AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->count());
    }

    public function test_field_job_show_includes_operational_traceability(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $show = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}");
        $show->assertStatus(200);
        $show->assertJsonStructure([
            'traceability' => [
                'posting_group_id',
                'reversal_posting_group_id',
                'overlap_signals',
                'linked_harvests',
                'machinery_sources',
                'stock_movements',
                'labour_lines',
                'labour_total',
            ],
        ]);
        $this->assertIsArray($show->json('traceability.linked_harvests'));
        $this->assertIsArray($show->json('traceability.machinery_sources'));
    }

    public function test_draft_cost_preview_computes_estimates_without_posting_or_writes(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $s['store']->id,
            'item_id' => $s['item']->id,
            'qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 3,
            'rate' => 10,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 4,
        ])->assertStatus(201);

        $before = [
            'posting_groups' => PostingGroup::where('tenant_id', $tenantId)->count(),
            'ledger_entries' => LedgerEntry::where('tenant_id', $tenantId)->count(),
            'allocation_rows' => AllocationRow::where('tenant_id', $tenantId)->count(),
            'stock_movements' => InvStockMovement::where('tenant_id', $tenantId)->count(),
            'stock_balances' => InvStockBalance::where('tenant_id', $tenantId)->count(),
        ];

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview");
        $preview->assertStatus(200);

        // Inputs estimate: 2 * 50 (WAC seeded by GRN)
        $this->assertEquals('100.00', $preview->json('inputs.subtotal_estimate'));
        $this->assertTrue($preview->json('inputs.all_known'));

        // Labour estimate: 3 * 10
        $this->assertEquals('30.00', $preview->json('labour.subtotal_estimate'));
        $this->assertTrue($preview->json('labour.all_known'));

        // Machinery estimate: 4 * 50 (rate card seeded)
        $this->assertEquals('200.00', $preview->json('machinery.subtotal_estimate'));
        $this->assertTrue($preview->json('machinery.all_known'));

        $this->assertEquals('330.00', $preview->json('summary.grand_total_estimate'));
        $this->assertTrue($preview->json('summary.all_known'));
        $this->assertEquals('330.00', $preview->json('summary.known_total_estimate'));
        $this->assertEquals(0, (int) $preview->json('summary.unknown_lines_count'));

        $after = [
            'posting_groups' => PostingGroup::where('tenant_id', $tenantId)->count(),
            'ledger_entries' => LedgerEntry::where('tenant_id', $tenantId)->count(),
            'allocation_rows' => AllocationRow::where('tenant_id', $tenantId)->count(),
            'stock_movements' => InvStockMovement::where('tenant_id', $tenantId)->count(),
            'stock_balances' => InvStockBalance::where('tenant_id', $tenantId)->count(),
        ];
        $this->assertEquals($before, $after, 'Draft cost preview must not write posting/accounting/inventory artifacts.');
    }

    public function test_draft_cost_preview_labour_explicit_amount_overrides_units_x_rate(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 2,
            'rate' => 10,
            'amount' => 999,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertEquals('999.00', $preview->json('labour.known_subtotal_estimate'));
        $this->assertTrue($preview->json('labour.all_known'));
        $this->assertEquals('999.00', $preview->json('labour.subtotal_estimate'));
        $this->assertEquals('EXPLICIT_AMOUNT', $preview->json('labour.lines.0.pricing_basis'));
        $this->assertEquals('999.00', $preview->json('labour.lines.0.amount_estimate'));
    }

    public function test_draft_cost_preview_labour_zero_explicit_amount_is_preserved_as_zero(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 2,
            'rate' => 10,
            'amount' => 0,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertEquals('0.00', $preview->json('labour.known_subtotal_estimate'));
        $this->assertEquals('0.00', $preview->json('labour.subtotal_estimate'));
        $this->assertEquals('0.00', $preview->json('labour.lines.0.amount_estimate'));
    }

    public function test_draft_cost_preview_machinery_explicit_amount_overrides_rate_card(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 4,
            'amount' => 123.45,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertEquals('123.45', $preview->json('machinery.subtotal_estimate'));
        $this->assertEquals('MANUAL_AMOUNT', $preview->json('machinery.lines.0.pricing_basis'));
    }

    public function test_draft_cost_preview_machinery_uses_rate_snapshot_when_amount_missing(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
            'rate_snapshot' => 12.5,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertEquals('25.00', $preview->json('machinery.subtotal_estimate'));
        $this->assertEquals('RATE_SNAPSHOT_ESTIMATE', $preview->json('machinery.lines.0.pricing_basis'));
    }

    public function test_draft_cost_preview_mixed_known_unknown_produces_partial_known_total_and_null_grand_total(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $machineNoRate = Machine::create([
            'tenant_id' => $s['tenant']->id,
            'code' => 'M-NR2-'.substr(uniqid(), -6),
            'name' => 'NoRate2',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
        ]);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        // Known machinery via rate snapshot
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $s['machine']->id,
            'usage_qty' => 2,
            'rate_snapshot' => 10,
        ])->assertStatus(201);

        // Unknown machinery (no rate card, no snapshot, no amount)
        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machineNoRate->id,
            'usage_qty' => 1,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertNull($preview->json('summary.grand_total_estimate'));
        $this->assertEquals('20.00', $preview->json('summary.known_total_estimate'));
        $this->assertGreaterThanOrEqual(1, (int) $preview->json('summary.unknown_lines_count'));
    }

    public function test_draft_cost_preview_inputs_unknown_when_stock_balance_missing(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        $uom = InvUom::create(['tenant_id' => $tenantId, 'code' => 'U2', 'name' => 'U2']);
        $cat = InvItemCategory::create(['tenant_id' => $tenantId, 'name' => 'Cat2']);
        $itemNoBal = InvItem::create([
            'tenant_id' => $tenantId,
            'name' => 'NoBalItem',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $storeNoBal = InvStore::create(['tenant_id' => $tenantId, 'name' => 'NoBalStore', 'type' => 'MAIN', 'is_active' => true]);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $storeNoBal->id,
            'item_id' => $itemNoBal->id,
            'qty' => 1,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertFalse($preview->json('inputs.all_known'));
        $this->assertNull($preview->json('inputs.subtotal_estimate'));
        $this->assertEquals('0.00', $preview->json('inputs.known_subtotal_estimate'));
        $this->assertEquals(1, (int) $preview->json('inputs.unknown_lines_count'));
        $this->assertContains('MISSING_STOCK_BALANCE', $preview->json('inputs.lines.0.warnings') ?? []);
    }

    public function test_draft_cost_preview_partial_labour_only_known(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];
        $tenantId = $s['tenant']->id;

        // Inputs unknown: new item/store without balance.
        $uom = InvUom::create(['tenant_id' => $tenantId, 'code' => 'U3', 'name' => 'U3']);
        $cat = InvItemCategory::create(['tenant_id' => $tenantId, 'name' => 'Cat3']);
        $itemNoBal = InvItem::create([
            'tenant_id' => $tenantId,
            'name' => 'NoBalItem2',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $storeNoBal = InvStore::create(['tenant_id' => $tenantId, 'name' => 'NoBalStore2', 'type' => 'MAIN', 'is_active' => true]);

        // Machinery unknown: machine without rate card.
        $machineNoRate = Machine::create([
            'tenant_id' => $tenantId,
            'code' => 'M-NR-LAB-'.substr(uniqid(), -6),
            'name' => 'NoRateLabOnly',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
        ]);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ])->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $storeNoBal->id,
            'item_id' => $itemNoBal->id,
            'qty' => 1,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machineNoRate->id,
            'usage_qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $s['worker']->id,
            'units' => 2,
            'rate' => 10,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview")->assertStatus(200);
        $this->assertEquals('20.00', $preview->json('labour.subtotal_estimate'));
        $this->assertEquals('20.00', $preview->json('summary.known_total_estimate'));
        $this->assertNull($preview->json('summary.grand_total_estimate'));
        $this->assertGreaterThanOrEqual(2, (int) $preview->json('summary.unknown_lines_count'));
    }

    public function test_draft_cost_preview_returns_unknown_for_machinery_without_rate_card_and_no_manual_amount(): void
    {
        $s = $this->seedTenantWithStockProjectWorkerMachine();
        $h = $s['headers'];

        $machineNoRate = Machine::create([
            'tenant_id' => $s['tenant']->id,
            'code' => 'M-NR-'.substr(uniqid(), -6),
            'name' => 'NoRate',
            'machine_type' => 'TRACTOR',
            'ownership_type' => 'OWNED',
            'status' => 'ACTIVE',
            'meter_unit' => 'HR',
        ]);

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $s['project']->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machineNoRate->id,
            'usage_qty' => 1.5,
        ])->assertStatus(201);

        $preview = $this->withHeaders($h)->getJson("/api/v1/crop-ops/field-jobs/{$jobId}/draft-cost-preview");
        $preview->assertStatus(200);
        $this->assertFalse($preview->json('machinery.all_known'));
        $this->assertNull($preview->json('machinery.subtotal_estimate'));
        $this->assertNull($preview->json('summary.grand_total_estimate'));

        $line = $preview->json('machinery.lines.0');
        $this->assertEquals('VALUED_ON_POSTING', $line['pricing_basis']);
        $this->assertContains('MISSING_RATE_CARD', $line['warnings'] ?? []);
    }
}
