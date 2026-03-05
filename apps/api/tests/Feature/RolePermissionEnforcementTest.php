<?php

namespace Tests\Feature;

use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use Database\Seeders\ModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role permission enforcement: accountant cannot manage users/modules or access platform;
 * operator cannot post/reverse; non-platform cannot access platform routes.
 */
class RolePermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function tenantHeaders(string $role): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => $role,
        ];
    }

    private function platformHeaders(string $role): array
    {
        return [
            'X-User-Id' => '00000000-0000-0000-0000-000000000001',
            'X-User-Role' => $role,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
    }

    public function test_accountant_cannot_access_tenant_users_index(): void
    {
        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->getJson('/api/tenant/users');
        $r->assertStatus(403);
    }

    public function test_accountant_cannot_create_tenant_user(): void
    {
        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->postJson('/api/tenant/users', [
                'name' => 'New',
                'email' => 'new@t1.test',
                'password' => 'secret123',
                'role' => 'operator',
            ]);
        $r->assertStatus(403);
    }

    public function test_accountant_cannot_update_tenant_user(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'U',
            'email' => 'u@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->putJson('/api/tenant/users/' . $user->id, ['is_enabled' => false]);
        $r->assertStatus(403);
    }

    public function test_accountant_cannot_delete_tenant_user(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'U',
            'email' => 'u@t1.test',
            'password' => null,
            'role' => 'operator',
            'is_enabled' => true,
        ]);

        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->deleteJson('/api/tenant/users/' . $user->id);
        $r->assertStatus(403);
    }

    public function test_accountant_can_get_tenant_modules_list_for_sidebar(): void
    {
        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->getJson('/api/tenant/modules');
        $r->assertStatus(200);
        $data = $r->json();
        $this->assertArrayHasKey('modules', $data);
        $projectsModule = collect($data['modules'])->firstWhere('key', 'projects_crop_cycles');
        $this->assertNotNull($projectsModule, 'projects_crop_cycles should be in response');
        $this->assertTrue($projectsModule['enabled'], 'Core module projects_crop_cycles should be enabled');
    }

    public function test_accountant_cannot_update_tenant_modules(): void
    {
        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->putJson('/api/tenant/modules', [
                'modules' => [['key' => 'ar_sales', 'enabled' => false]],
            ]);
        $r->assertStatus(403);
    }

    public function test_accountant_cannot_access_platform_tenants(): void
    {
        $r = $this->withHeaders($this->platformHeaders('accountant'))
            ->getJson('/api/platform/tenants');
        $r->assertStatus(403);
    }

    public function test_tenant_admin_cannot_access_platform_tenants(): void
    {
        $r = $this->withHeaders($this->platformHeaders('tenant_admin'))
            ->getJson('/api/platform/tenants');
        $r->assertStatus(403);
    }

    public function test_accountant_can_access_dashboard_summary(): void
    {
        $r = $this->withHeaders($this->tenantHeaders('accountant'))
            ->getJson('/api/dashboard/summary');
        $r->assertStatus(200);
    }

    public function test_operator_cannot_post_grn(): void
    {
        $this->enableInventory();
        $grn = $this->createDraftGrn();

        $r = $this->withHeaders($this->tenantHeaders('operator'))
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'op-test',
            ]);
        $r->assertStatus(403);
    }

    public function test_operator_cannot_reverse_grn(): void
    {
        $this->enableInventory();
        $grn = $this->createDraftGrn();
        // Role middleware runs first: operator is not allowed on post/reverse routes (tenant_admin,accountant only).
        $r = $this->withHeaders($this->tenantHeaders('operator'))
            ->postJson("/api/v1/inventory/grns/{$grn->id}/reverse", [
                'posting_date' => '2024-06-16',
                'reason' => 'Test',
            ]);
        $r->assertStatus(403);
    }

    public function test_tenant_admin_can_access_tenant_users_and_modules(): void
    {
        $r1 = $this->withHeaders($this->tenantHeaders('tenant_admin'))->getJson('/api/tenant/users');
        $r1->assertStatus(200);

        $r2 = $this->withHeaders($this->tenantHeaders('tenant_admin'))->getJson('/api/tenant/modules');
        $r2->assertStatus(200);
    }

    public function test_platform_admin_can_access_platform_tenants(): void
    {
        $r = $this->withHeaders($this->platformHeaders('platform_admin'))
            ->getJson('/api/platform/tenants');
        $r->assertStatus(200);
    }

    private ?Tenant $tenant = null;

    private function enableInventory(): void
    {
        $m = Module::where('key', 'inventory')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function createDraftGrn(): InvGrn
    {
        $uom = InvUom::create(['tenant_id' => $this->tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Cat']);
        $item = InvItem::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Item',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $this->tenant->id, 'name' => 'S1', 'type' => 'MAIN', 'is_active' => true]);
        $grn = InvGrn::create([
            'tenant_id' => $this->tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        InvGrnLine::create([
            'tenant_id' => $this->tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 10,
            'unit_cost' => 10,
            'line_total' => 100,
        ]);

        return $grn;
    }
}
