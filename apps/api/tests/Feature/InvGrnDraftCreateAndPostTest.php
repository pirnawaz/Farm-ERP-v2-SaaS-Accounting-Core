<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\InvUom;
use App\Services\TenantContext;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\PostingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvGrnDraftCreateAndPostTest extends TestCase
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

    public function test_create_grn_draft_and_post_updates_stock(): void
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
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main Store', 'type' => 'MAIN', 'is_active' => true]);

        $create = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/v1/inventory/grns', [
                'doc_no' => 'GRN-001',
                'store_id' => $store->id,
                'doc_date' => '2024-06-15',
                'lines' => [
                    ['item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50],
                ],
            ]);
        $create->assertStatus(201);
        $grnId = $create->json('id');
        $this->assertEquals('DRAFT', $create->json('status'));

        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grnId}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'grn-001-post',
            ]);
        $post->assertStatus(201);
        $this->assertNotNull($post->json('id'));

        $grn = InvGrn::find($grnId);
        $this->assertEquals('POSTED', $grn->status);
        $this->assertNotNull($grn->posting_group_id);

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($bal);
        $this->assertEquals(10, (float) $bal->qty_on_hand);
        $this->assertEquals(500, (float) $bal->value_on_hand);
        $this->assertEquals(50, (float) $bal->wac_cost);

        $mov = InvStockMovement::where('tenant_id', $tenant->id)->where('source_type', 'inv_grn')->where('source_id', $grnId)->first();
        $this->assertNotNull($mov);
        $this->assertEquals(10, (float) $mov->qty_delta);
        $this->assertEquals(500, (float) $mov->value_delta);
    }
}
