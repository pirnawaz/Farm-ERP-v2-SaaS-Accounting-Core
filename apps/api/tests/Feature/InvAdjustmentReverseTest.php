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
use App\Models\InvAdjustment;
use App\Models\InvAdjustmentLine;
use App\Models\InvStockBalance;
use App\Models\InvStockMovement;
use App\Models\PostingGroup;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvAdjustmentReverseTest extends TestCase
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

    public function test_post_adjustment_then_reverse_restores_balances_and_reverses_postings(): void
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

        $grn = InvGrn::create(['tenant_id' => $tenant->id, 'doc_no' => 'GRN-1', 'store_id' => $store->id, 'doc_date' => '2024-06-15', 'status' => 'DRAFT']);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", ['posting_date' => '2024-06-15', 'idempotency_key' => 'g1']);

        $adj = InvAdjustment::create(['tenant_id' => $tenant->id, 'doc_no' => 'ADJ-1', 'store_id' => $store->id, 'reason' => 'LOSS', 'doc_date' => '2024-06-16', 'status' => 'DRAFT']);
        InvAdjustmentLine::create(['tenant_id' => $tenant->id, 'adjustment_id' => $adj->id, 'item_id' => $item->id, 'qty_delta' => -2]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/adjustments/{$adj->id}/post", ['posting_date' => '2024-06-16', 'idempotency_key' => 'a1']);

        $rev = $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/adjustments/{$adj->id}/reverse", ['posting_date' => '2024-06-17', 'reason' => 'Correction']);
        $rev->assertStatus(201);

        $adj->refresh();
        $this->assertEquals('REVERSED', $adj->status);

        $bal = InvStockBalance::where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('item_id', $item->id)->first();
        $this->assertNotNull($bal);
        $this->assertEquals(10, (float) $bal->qty_on_hand);
        $this->assertEquals(500, (float) $bal->value_on_hand);

        $reversalPg = PostingGroup::where('reversal_of_posting_group_id', $adj->posting_group_id)->first();
        $this->assertNotNull($reversalPg);
        $negMov = InvStockMovement::where('posting_group_id', $reversalPg->id)->where('movement_type', 'ADJUST')->first();
        $this->assertNotNull($negMov);
        $this->assertEquals(2, (float) $negMov->qty_delta);
    }
}
