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
use App\Models\GrnPaymentAllocation;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\BillPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class APCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
        (new ModulesSeeder)->run();
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

    private function seedTenantWithSupplier(): array
    {
        $tenant = Tenant::create(['name' => 'AP Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['inventory', 'treasury_payments', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier One',
            'party_types' => ['VENDOR'],
        ]);
        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'BAG', 'name' => 'Bag']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Goods']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Item',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);
        $store = InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'type' => 'MAIN', 'is_active' => true]);

        return compact('tenant', 'cropCycle', 'supplier', 'store', 'item');
    }

    /**
     * 1) Posting a bill increases AP in the ledger.
     */
    public function test_posting_bill_increases_ap_in_ledger(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 10, 'line_total' => 100]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-core-bill-1',
            ])
            ->assertStatus(201);

        $apAccount = \App\Models\Account::where('tenant_id', $tenant->id)->where('code', 'AP')->first();
        $this->assertNotNull($apAccount);
        $pg = PostingGroup::where('source_type', 'INVENTORY_GRN')->where('source_id', $grn->id)->first();
        $this->assertNotNull($pg);
        $credit = LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $apAccount->id)->first();
        $this->assertNotNull($credit);
        $this->assertEquals(100, (float) $credit->credit_amount);
    }

    /**
     * 2) Posting a supplier payment decreases AP in the ledger.
     */
    public function test_posting_supplier_payment_decreases_ap_in_ledger(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];
        $cropCycle = $data['cropCycle'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 10, 'line_total' => 100]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-core-bill-2',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 40,
            'payment_date' => '2024-06-05',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'ap-core-pmt-2',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $apAccount = \App\Models\Account::where('tenant_id', $tenant->id)->where('code', 'AP')->first();
        $payment->refresh();
        $pgPayment = PostingGroup::find($payment->posting_group_id);
        $debit = LedgerEntry::where('posting_group_id', $pgPayment->id)->where('account_id', $apAccount->id)->first();
        $this->assertNotNull($debit);
        $this->assertEquals(40, (float) $debit->debit_amount);

        $glApNet = LedgerEntry::where('ledger_entries.tenant_id', $tenant->id)
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.code', 'AP')
            ->selectRaw('COALESCE(SUM(ledger_entries.credit_amount - ledger_entries.debit_amount), 0) AS net')
            ->value('net');
        $this->assertEquals(60, (float) $glApNet, 'GL AP balance (credit - debit) should be 100 - 40 = 60');
    }

    /**
     * 3) Apply/unapply is audit-only; no ledger mutation.
     */
    public function test_apply_unapply_is_audit_only_no_ledger_mutation(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];
        $cropCycle = $data['cropCycle'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 5, 'unit_cost' => 20, 'line_total' => 100]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-core-audit-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 50,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'ap-core-audit-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $ledgerCountBefore = \App\Models\LedgerEntry::where('tenant_id', $tenant->id)->count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-bills", [
                'mode' => 'FIFO',
                'allocation_date' => '2024-06-10',
            ])
            ->assertStatus(201);

        $this->assertGreaterThan(0, GrnPaymentAllocation::where('tenant_id', $tenant->id)->where('payment_id', $payment->id)->where('status', 'ACTIVE')->count());
        $ledgerCountAfterApply = \App\Models\LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertEquals($ledgerCountBefore, $ledgerCountAfterApply, 'Apply must not create ledger entries');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-bills")
            ->assertStatus(200);

        $this->assertEquals(0, GrnPaymentAllocation::where('tenant_id', $tenant->id)->where('payment_id', $payment->id)->where('status', 'ACTIVE')->count());
        $this->assertGreaterThan(0, GrnPaymentAllocation::where('tenant_id', $tenant->id)->where('payment_id', $payment->id)->where('status', 'VOID')->count());
        $ledgerCountAfterUnapply = \App\Models\LedgerEntry::where('tenant_id', $tenant->id)->count();
        $this->assertEquals($ledgerCountBefore, $ledgerCountAfterUnapply, 'Unapply must not change ledger entries');
    }

    /**
     * 4) Reversal guards: cannot reverse bill with ACTIVE allocations; cannot reverse payment with ACTIVE allocations.
     */
    public function test_reversal_guards_prevent_contradictions(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];
        $cropCycle = $data['cropCycle'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 10, 'line_total' => 100]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-guard-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 30,
            'payment_date' => '2024-06-05',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'ap-guard-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-bills", [
                'mode' => 'FIFO',
                'allocation_date' => '2024-06-05',
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/reverse", [
                'posting_date' => '2024-06-06',
                'reason' => 'Test',
            ])
            ->assertStatus(422);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-06',
                'reason' => 'Test',
            ])
            ->assertStatus(409);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-bills")
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-06',
                'reason' => 'Test',
            ])
            ->assertStatus(201);
    }

    /**
     * 5) AP ageing totals reconcile to open bills for as_of.
     */
    public function test_ap_ageing_reconciles_to_open_bills(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 25, 'line_total' => 250]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-ageing-1',
            ])
            ->assertStatus(201);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ap-ageing?as_of=2024-06-15');
        $res->assertStatus(200);
        $body = $res->json();
        $this->assertEquals('250.00', $body['totals']['total_outstanding']);

        $openTotal = app(BillPaymentService::class)->getSupplierOpenBillsTotal($supplier->id, $tenant->id, '2024-06-15');
        $this->assertEqualsWithDelta(250, $openTotal, 0.01);
    }

    /**
     * 6) AP control reconciliation: unapplied supplier payments explain delta.
     */
    public function test_ap_control_reconciliation_explains_delta_by_unapplied(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];
        $cropCycle = $data['cropCycle'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 10, 'line_total' => 100]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-recon-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 100,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-recon-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ap-control-reconciliation?as_of=2024-06-05');
        $res->assertStatus(200);
        $body = $res->json();
        $this->assertEquals(100, $body['subledger_open_bills_total']);
        $this->assertEquals(0, $body['gl_ap_total']);
        $this->assertEquals(100, $body['delta']);
        $this->assertEquals(100, $body['unapplied_supplier_payments_total']);
        $this->assertEquals(100, $body['explained_delta']);
    }

    /**
     * 7) Supplier balances: positive net_balance = we owe supplier.
     */
    public function test_supplier_balances_credit_vs_debit(): void
    {
        $data = $this->seedTenantWithSupplier();
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $store = $data['store'];
        $item = $data['item'];

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-1',
            'store_id' => $store->id,
            'doc_date' => '2024-06-01',
            'status' => 'DRAFT',
            'supplier_party_id' => $supplier->id,
        ]);
        InvGrnLine::create(['tenant_id' => $tenant->id, 'grn_id' => $grn->id, 'item_id' => $item->id, 'qty' => 10, 'unit_cost' => 50, 'line_total' => 500]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ap-bal-1',
            ])
            ->assertStatus(201);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/supplier-balances?as_of=2024-06-10');
        $res->assertStatus(200);
        $body = $res->json();
        $this->assertNotEmpty($body['rows']);
        $row = collect($body['rows'])->firstWhere('supplier_party_id', $supplier->id);
        $this->assertNotNull($row);
        $this->assertEquals(500, $row['open_bills_total']);
        $this->assertEquals(0, $row['unapplied_total']);
        $this->assertEquals(500, $row['net_balance'], 'Positive net_balance = we owe supplier');
    }
}
