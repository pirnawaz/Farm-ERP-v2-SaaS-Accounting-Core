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
use App\Models\CropActivityType;
use App\Models\CropActivity;
use App\Models\CropActivityInput;
use App\Models\CropActivityLabour;
use App\Models\InvStockBalance;
use App\Models\LabWorkerBalance;
use App\Models\PostingGroup;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ActivityPostIdempotencyTest extends TestCase
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

    public function test_posting_activity_twice_with_same_idempotency_returns_same_pg_and_no_double_effects(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModule($tenant, 'inventory');
        $this->enableModule($tenant, 'crop_ops');

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fertilizer']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id, 'name' => 'Fertilizer Bag', 'uom_id' => $uom->id,
            'category_id' => $cat->id, 'valuation_method' => 'WAC', 'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main Store', 'type' => 'MAIN', 'is_active' => true]);
        $cc = CropCycle::create(['tenant_id' => $tenant->id, 'name' => 'Wheat 2026', 'start_date' => '2024-01-01', 'end_date' => '2026-12-31', 'status' => 'OPEN']);
        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Hari', 'party_types' => ['HARI']]);
        $project = Project::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'crop_cycle_id' => $cc->id, 'name' => 'Project A', 'status' => 'ACTIVE']);

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-1', 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-01', 'idempotency_key' => 'g1']);

        $worker = LabWorker::create(['tenant_id' => $tenant->id, 'name' => 'W1', 'worker_type' => 'HARI', 'rate_basis' => 'DAILY']);
        $type = CropActivityType::create(['tenant_id' => $tenant->id, 'name' => 'Sowing', 'is_active' => true]);

        $cr = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/crop-ops/activities', [
                'doc_no' => 'ACT-1',
                'activity_type_id' => $type->id,
                'activity_date' => '2024-06-15',
                'crop_cycle_id' => $cc->id,
                'project_id' => $project->id,
                'inputs' => [['store_id' => $store->id, 'item_id' => $item->id, 'qty' => 2]],
                'labour' => [['worker_id' => $worker->id, 'units' => 1, 'rate' => 500]],
            ]);
        $cr->assertStatus(201);
        $activityId = $cr->json('id');

        $key = 'idem-act-1';
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/crop-ops/activities/{$activityId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r1->assertStatus(201);
        $pgId1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/crop-ops/activities/{$activityId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => $key]);
        $r2->assertStatus(201);
        $pgId2 = $r2->json('id');

        $this->assertEquals($pgId1, $pgId2);
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('idempotency_key', $key)->count());

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($bal);
        $this->assertEquals(8, (float) $bal->qty_on_hand);

        $workerBal = LabWorkerBalance::where('tenant_id', $tenant->id)->where('worker_id', $worker->id)->first();
        $this->assertNotNull($workerBal);
        $this->assertEquals(500, (float) $workerBal->payable_balance);
    }
}
