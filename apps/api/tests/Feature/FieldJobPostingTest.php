<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\LabWorker;
use App\Models\Machine;
use App\Models\MachineRateCard;
use App\Models\FieldJob;
use App\Models\InvStockBalance;
use App\Models\LabWorkerBalance;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Services\TenantContext;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;
use Tests\TestCase;

class FieldJobPostingTest extends TestCase
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

    public function test_post_field_job_then_reverse_restores_stock_and_labour_balance(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'FJ', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Input']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Seed', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id, 'name' => '2026', 'start_date' => '2024-01-01', 'end_date' => '2026-12-31', 'status' => 'OPEN',
        ]);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'P1', 'status' => 'ACTIVE',
        ]);

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-FJ', 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-01', 'idempotency_key' => 'grn-fj-1']);

        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'W1', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);
        $machine = Machine::create([
            'tenant_id' => $tenant->id,
            'code' => 'TR1',
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
            'base_rate' => 40.00,
            'cost_plus_percent' => null,
            'includes_fuel' => true,
            'includes_operator' => true,
            'includes_maintenance' => true,
            'is_active' => true,
        ]);

        $h = ['X-Tenant-Id' => $tenant->id, 'X-User-Role' => 'accountant'];

        $fj = $this->withHeaders($h)->postJson('/api/v1/crop-ops/field-jobs', [
            'job_date' => '2024-06-15',
            'project_id' => $project->id,
        ]);
        $fj->assertStatus(201);
        $jobId = $fj->json('id');

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/inputs", [
            'store_id' => $store->id,
            'item_id' => $item->id,
            'qty' => 2,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/labour", [
            'worker_id' => $worker->id,
            'units' => 1,
            'rate' => 100,
        ])->assertStatus(201);

        $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/machines", [
            'machine_id' => $machine->id,
            'usage_qty' => 3.5,
        ])->assertStatus(201);

        $post = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-post-1',
        ]);
        $post->assertStatus(201);

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->first();
        $this->assertNotNull($pg);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'FIELD_JOB')->where('source_id', $jobId)->count());

        $usageRows = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_USAGE')->count();
        $this->assertEquals(1, $usageRows);
        $finRows = AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'MACHINERY_SERVICE')->count();
        $this->assertEquals(1, $finRows);

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertEquals(8, (float) $bal->qty_on_hand);

        $wb = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertEquals(100, (float) $wb->payable_balance);

        $fjModel = FieldJob::find($jobId);
        $this->assertEquals('POSTED', $fjModel->status);
        $this->assertNotNull($fjModel->posted_at);

        $idem = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/post", [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'fj-post-1',
        ]);
        $idem->assertStatus(201);
        $this->assertEquals($pg->id, $idem->json('id'));

        $rev = $this->withHeaders($h)->postJson("/api/v1/crop-ops/field-jobs/{$jobId}/reverse", [
            'posting_date' => '2024-06-20',
            'reason' => 'test reverse',
        ]);
        $rev->assertStatus(201);

        $fjModel->refresh();
        $this->assertEquals('REVERSED', $fjModel->status);
        $this->assertNotNull($fjModel->reversed_at);
        $this->assertNotNull($fjModel->reversal_posting_group_id);

        $bal2 = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertEquals(10, (float) $bal2->qty_on_hand);

        $wb2 = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertEquals(0, (float) $wb2->payable_balance);
    }
}
