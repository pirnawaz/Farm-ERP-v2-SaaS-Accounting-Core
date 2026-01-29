<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\InvUom;
use App\Models\InvItemCategory;
use App\Models\InvItem;
use App\Models\InvStore;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\Payment;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class SupplierPayableLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    /**
     * Test 1: Supplier payable appears after GRN post.
     */
    public function test_supplier_payable_appears_after_grn_post(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['inventory', 'treasury_payments']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $najam = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Najam',
            'party_types' => ['VENDOR'],
        ]);

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

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'supplier_party_id' => $najam->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 100, 'line_total' => 1000]);

        $postResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'grn-supplier-1',
            ]);
        $postResponse->assertStatus(201);

        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$najam->id}/balances");
        $balancesResponse->assertStatus(200);
        $balances = $balancesResponse->json();
        $this->assertGreaterThan(0, (float) $balances['outstanding_total'], 'Party payable balance should be > 0 after GRN post');
        $this->assertEquals('1000.00', $balances['outstanding_total']);
        $this->assertEquals('1000.00', $balances['supplier_payable_outstanding']);

        $statementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$najam->id}/statement?from=2024-01-01&to=2024-12-31");
        $statementResponse->assertStatus(200);
        $statement = $statementResponse->json();
        $this->assertEquals('1000.00', $statement['summary']['closing_balance_payable'], 'Statement closing balance payable must match balances API');
        $this->assertEquals('1000.00', $statement['summary']['supplier_payable_total']);
    }

    /**
     * Test 2: Supplier payment reduces outstanding.
     */
    public function test_supplier_payment_reduces_outstanding(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['inventory', 'treasury_payments']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $najam = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Najam',
            'party_types' => ['VENDOR'],
        ]);

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

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'supplier_party_id' => $najam->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 100, 'line_total' => 1000]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'grn-supplier-2',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $najam->id,
            'direction' => 'OUT',
            'amount' => 400.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $postPaymentResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-supplier-2',
                'crop_cycle_id' => $cropCycle->id,
            ]);
        $postPaymentResponse->assertStatus(201);

        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$najam->id}/balances");
        $balancesResponse->assertStatus(200);
        $balances = $balancesResponse->json();
        $this->assertEquals('600.00', $balances['outstanding_total'], 'Outstanding should be 1000 - 400 = 600');

        $statementResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$najam->id}/statement?from=2024-01-01&to=2024-12-31");
        $statementResponse->assertStatus(200);
        $statement = $statementResponse->json();
        $this->assertEquals('600.00', $statement['summary']['closing_balance_payable']);
    }

    /**
     * Test 3: Reversal unwinds payable.
     */
    public function test_grn_reversal_unwinds_supplier_payable(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['inventory', 'treasury_payments']);

        $najam = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Najam',
            'party_types' => ['VENDOR'],
        ]);

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

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'supplier_party_id' => $najam->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 100, 'line_total' => 1000]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'grn-supplier-3',
            ])
            ->assertStatus(201);

        $reverseResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/reverse", [
                'posting_date' => '2024-06-16',
                'reason' => 'Wrong receipt',
            ]);
        $reverseResponse->assertStatus(201);

        $grn->refresh();
        $this->assertEquals('REVERSED', $grn->status);

        $balancesResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$najam->id}/balances");
        $balancesResponse->assertStatus(200);
        $balances = $balancesResponse->json();
        $this->assertEquals('0.00', $balances['outstanding_total'], 'Supplier payable should be 0 after GRN reversal');
        $this->assertEquals('0.00', $balances['supplier_payable_outstanding']);
    }

    /**
     * Test 4: Overpayment blocked with 422.
     */
    public function test_overpayment_blocked_with_422(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['inventory', 'treasury_payments']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $najam = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Najam',
            'party_types' => ['VENDOR'],
        ]);

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

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-15',
            'status' => 'DRAFT',
            'supplier_party_id' => $najam->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 5, 'unit_cost' => 100, 'line_total' => 500]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'grn-supplier-4',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $najam->id,
            'direction' => 'OUT',
            'amount' => 600.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $postPaymentResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'payment-overpay-4',
                'crop_cycle_id' => $cropCycle->id,
            ]);
        $postPaymentResponse->assertStatus(422);
        $data = $postPaymentResponse->json();
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('exceeds outstanding payable', $postPaymentResponse->getContent());
    }
}
