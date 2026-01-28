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
use App\Models\InvStockBalance;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\Machine;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvIssuePostReducesStockAndCreatesAllocationsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_post_issue_reduces_stock_and_creates_allocations(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

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

        $cr = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/issues', [
                'doc_no' => 'ISS-1', 'store_id' => $store->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
                'doc_date' => '2024-06-15', 'lines' => [['item_id' => $item->id, 'qty' => 2]],
            ]);
        $cr->assertStatus(201);
        $issueId = $cr->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/issues/{$issueId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'i1']);
        $post->assertStatus(201);

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($bal);
        $this->assertEquals(8, (float) $bal->qty_on_hand);
        $this->assertEquals(400, (float) $bal->value_on_hand);

        $pgId = $post->json('id');
        $alloc = AllocationRow::where('posting_group_id', $pgId)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($project->id, $alloc->project_id);
        $this->assertEquals(100, (float) $alloc->amount);

        $leCount = LedgerEntry::where('posting_group_id', $pgId)->count();
        $this->assertGreaterThanOrEqual(2, $leCount);
    }

    public function test_post_issue_with_machine_id_propagates_to_allocation_row(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

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

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-1', 'store_id' => $store->id, 'doc_date' => '2024-06-01', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-01', 'idempotency_key' => 'g1']);

        $cr = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/issues', [
                'doc_no' => 'ISS-2', 'store_id' => $store->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
                'doc_date' => '2024-06-15', 'machine_id' => $machine->id, 'lines' => [['item_id' => $item->id, 'qty' => 2]],
            ]);
        $cr->assertStatus(201);
        $issueId = $cr->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/issues/{$issueId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'i2']);
        $post->assertStatus(201);

        $pgId = $post->json('id');
        $alloc = AllocationRow::where('posting_group_id', $pgId)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($project->id, $alloc->project_id);
        $this->assertEquals($machine->id, $alloc->machine_id);
        $this->assertEquals(100, (float) $alloc->amount);
    }

    public function test_post_issue_without_machine_id_has_null_machine_id_in_allocation_row(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

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

        $cr = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/issues', [
                'doc_no' => 'ISS-3', 'store_id' => $store->id, 'crop_cycle_id' => $cc->id, 'project_id' => $project->id,
                'doc_date' => '2024-06-15', 'lines' => [['item_id' => $item->id, 'qty' => 2]],
            ]);
        $cr->assertStatus(201);
        $issueId = $cr->json('id');

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/issues/{$issueId}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'i3']);
        $post->assertStatus(201);

        $pgId = $post->json('id');
        $alloc = AllocationRow::where('posting_group_id', $pgId)->first();
        $this->assertNotNull($alloc);
        $this->assertEquals($project->id, $alloc->project_id);
        $this->assertNull($alloc->machine_id);
        $this->assertEquals(100, (float) $alloc->amount);
    }
}
