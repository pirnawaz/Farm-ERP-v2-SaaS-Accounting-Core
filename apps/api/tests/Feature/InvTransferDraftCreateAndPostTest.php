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
use App\Models\InvStockMovement;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvTransferDraftCreateAndPostTest extends TestCase
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

    public function test_grn_then_transfer_store_a_to_b_assert_balances_and_movements(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fertilizer']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fertilizer Bag',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $storeA = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Store A', 'type' => 'MAIN', 'is_active' => true]);
        $storeB = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Store B', 'type' => 'MAIN', 'is_active' => true]);

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-1', 'store_id' => $storeA->id, 'doc_date' => '2024-06-15', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'k1']);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/transfers', [
                'doc_no' => 'TRF-1',
                'from_store_id' => $storeA->id,
                'to_store_id' => $storeB->id,
                'doc_date' => '2024-06-16',
                'lines' => [['item_id' => $item->id, 'qty' => 3]],
            ]);
        $create->assertStatus(201);
        $transferId = $create->json('id');
        $this->assertEquals('DRAFT', $create->json('status'));

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/transfers/{$transferId}/post", ['posting_date' => '2024-06-16', 'idempotency_key' => 't1']);
        $post->assertStatus(201);

        $balA = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $storeA->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($balA);
        $this->assertEquals(7, (float) $balA->qty_on_hand);
        $this->assertEquals(350, (float) $balA->value_on_hand);

        $balB = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $storeB->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($balB);
        $this->assertEquals(3, (float) $balB->qty_on_hand);
        $this->assertEquals(150, (float) $balB->value_on_hand);

        $out = InvStockMovement::where('tenant_id', $tenant->id)->where('source_type', 'inv_transfer')->where('source_id', $transferId)
            ->where('movement_type', 'TRANSFER_OUT')->first();
        $this->assertNotNull($out);
        $this->assertEquals(-3, (float) $out->qty_delta);
        $this->assertEquals(-150, (float) $out->value_delta);
        $this->assertEquals(50, (float) $out->unit_cost_snapshot);

        $in = InvStockMovement::where('tenant_id', $tenant->id)->where('source_type', 'inv_transfer')->where('source_id', $transferId)
            ->where('movement_type', 'TRANSFER_IN')->first();
        $this->assertNotNull($in);
        $this->assertEquals(3, (float) $in->qty_delta);
        $this->assertEquals(150, (float) $in->value_delta);
        $this->assertEquals(50, (float) $in->unit_cost_snapshot);
    }
}
