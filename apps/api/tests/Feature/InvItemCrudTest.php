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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class InvItemCrudTest extends TestCase
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

    public function test_can_update_item(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $uom2 = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'KG', 'name' => 'Kilogram']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Fertilizer']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fertilizer Bag',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->patchJson("/api/v1/inventory/items/{$item->id}", [
                'name' => 'Fertilizer Bag Updated',
                'sku' => 'FB-001',
                'category_id' => $cat->id,
                'uom_id' => $uom2->id,
                'valuation_method' => 'FIFO',
                'is_active' => true,
            ]);
        $r->assertStatus(200);
        $r->assertJsonPath('name', 'Fertilizer Bag Updated');
        $r->assertJsonPath('sku', 'FB-001');
        $r->assertJsonPath('uom.id', $uom2->id);
        $r->assertJsonPath('valuation_method', 'FIFO');
        $r->assertJsonPath('can_delete', true);

        $item->refresh();
        $this->assertEquals('Fertilizer Bag Updated', $item->name);
        $this->assertEquals('FIFO', $item->valuation_method);
    }

    public function test_can_deactivate_item(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Deact Item',
            'uom_id' => $uom->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/items/{$item->id}/deactivate");
        $r->assertStatus(200);
        $r->assertJsonPath('is_active', false);

        $item->refresh();
        $this->assertFalse($item->is_active);
    }

    public function test_can_activate_item(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Act Item',
            'uom_id' => $uom->id,
            'valuation_method' => 'WAC',
            'is_active' => false,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/items/{$item->id}/activate");
        $r->assertStatus(200);
        $r->assertJsonPath('is_active', true);

        $item->refresh();
        $this->assertTrue($item->is_active);
    }

    public function test_cannot_delete_used_item_returns_422(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Used Item',
            'uom_id' => $uom->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => now()->toDateString(),
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 1,
            'unit_cost' => 10,
            'line_total' => 10,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->deleteJson("/api/v1/inventory/items/{$item->id}");
        $r->assertStatus(422);
        $r->assertJsonPath('message', 'Cannot delete an item that has transactions. Deactivate it instead.');

        $this->assertDatabaseHas('inv_items', ['id' => $item->id]);
    }

    public function test_can_delete_unused_item_returns_204(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Unused Item',
            'uom_id' => $uom->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->deleteJson("/api/v1/inventory/items/{$item->id}");
        $r->assertStatus(204);

        $this->assertDatabaseMissing('inv_items', ['id' => $item->id]);
    }

    public function test_index_includes_can_delete(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'List Item',
            'uom_id' => $uom->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/v1/inventory/items');
        $r->assertStatus(200);
        $first = collect($r->json())->firstWhere('id', $item->id);
        $this->assertNotNull($first);
        $this->assertArrayHasKey('can_delete', $first);
        $this->assertTrue($first['can_delete']);
    }
}
