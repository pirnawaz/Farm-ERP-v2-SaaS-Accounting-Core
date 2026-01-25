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
use App\Models\InvTransfer;
use App\Models\InvTransferLine;
use App\Models\InvStockBalance;
use App\Models\PostingGroup;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvTransferPostIdempotencyTest extends TestCase
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

    public function test_posting_transfer_twice_with_same_idempotency_does_not_double_move(): void
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
        $storeA = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Store A', 'type' => 'MAIN', 'is_active' => true]);
        $storeB = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Store B', 'type' => 'MAIN', 'is_active' => true]);

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-1', 'store_id' => $storeA->id, 'doc_date' => '2024-06-15', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'g1']);

        $transfer = InvTransfer::create(['tenant_id' => $tenant->id, 'doc_no' => 'TRF-1', 'from_store_id' => $storeA->id, 'to_store_id' => $storeB->id, 'doc_date' => '2024-06-16', 'status' => 'DRAFT']);
        InvTransferLine::create(['tenant_id' => $tenant->id, 'transfer_id' => $transfer->id, 'item_id' => $item->id, 'qty' => 3]);

        $key = 'idem-trf-1';
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/transfers/{$transfer->id}/post", ['posting_date' => '2024-06-16', 'idempotency_key' => $key]);
        $r1->assertStatus(201);
        $pgId1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/transfers/{$transfer->id}/post", ['posting_date' => '2024-06-16', 'idempotency_key' => $key]);
        $r2->assertStatus(201);
        $this->assertEquals($pgId1, $r2->json('id'));
        $this->assertEquals(1, PostingGroup::where('tenant_id', $tenant->id)->where('idempotency_key', $key)->count());

        $balA = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $storeA->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($balA);
        $this->assertEquals(7, (float) $balA->qty_on_hand);
        $balB = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $storeB->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($balB);
        $this->assertEquals(3, (float) $balB->qty_on_hand);
    }
}
