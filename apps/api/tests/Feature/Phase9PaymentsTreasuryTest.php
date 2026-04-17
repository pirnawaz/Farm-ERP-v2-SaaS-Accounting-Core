<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\BillPaymentService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase9PaymentsTreasuryTest extends TestCase
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
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    public function test_posted_supplier_payment_credits_explicit_source_asset_account(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P9 Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'reports']);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor P9',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'SINV-P9-1',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 500,
            'tax_amount' => 0,
            'total_amount' => 500,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Service',
            'line_total' => 500,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'p9-sinv-1',
            ])
            ->assertStatus(201);

        $bankGl = Account::create([
            'tenant_id' => $tenant->id,
            'code' => 'BANK-P9',
            'name' => 'HSBC Operating',
            'type' => 'asset',
            'is_system' => false,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 200,
            'payment_date' => '2024-06-10',
            'method' => 'BANK',
            'source_account_id' => $bankGl->id,
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'p9-pmt-1',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $payment->refresh();
        $this->assertEquals('POSTED', $payment->status);
        $this->assertNotNull($payment->posting_group_id);

        $creditLine = LedgerEntry::where('posting_group_id', $payment->posting_group_id)
            ->where('credit_amount', '>', 0)
            ->first();
        $this->assertNotNull($creditLine);
        $this->assertEquals($bankGl->id, $creditLine->account_id);

        $svc = app(BillPaymentService::class);
        $this->assertEqualsWithDelta(500.0, $svc->getSupplierInvoiceOutstanding($invoice->id, $tenant->id, null), 0.02);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-supplier-invoices", [
                'mode' => 'FIFO',
                'allocation_date' => '2024-06-10',
            ])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(300.0, $svc->getSupplierInvoiceOutstanding($invoice->id, $tenant->id, null), 0.02);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertStatus(200)
            ->assertJsonPath('payment_applications.0.payment_id', $payment->id);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/treasury-supplier-outflows?from=2024-06-01&to=2024-06-30')
            ->assertStatus(200)
            ->assertJsonPath('total_paid', '200.00');
    }

    public function test_one_payment_many_bills_and_many_payments_one_bill(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P9 Multi', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments']);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor Multi',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        $makePostedInvoice = function (string $ref, float $total) use ($tenant, $supplier, $project) {
            $inv = SupplierInvoice::create([
                'tenant_id' => $tenant->id,
                'party_id' => $supplier->id,
                'project_id' => $project->id,
                'reference_no' => $ref,
                'invoice_date' => '2024-06-01',
                'currency_code' => 'GBP',
                'subtotal_amount' => $total,
                'tax_amount' => 0,
                'total_amount' => $total,
                'status' => SupplierInvoice::STATUS_DRAFT,
            ]);
            SupplierInvoiceLine::create([
                'tenant_id' => $tenant->id,
                'supplier_invoice_id' => $inv->id,
                'line_no' => 1,
                'description' => 'Line',
                'line_total' => $total,
                'tax_amount' => 0,
            ]);
            $this->withHeader('X-Tenant-Id', $tenant->id)
                ->withHeader('X-User-Role', 'accountant')
                ->postJson("/api/supplier-invoices/{$inv->id}/post", [
                    'posting_date' => '2024-06-05',
                    'idempotency_key' => 'p9-post-' . $ref,
                ])
                ->assertStatus(201);

            return $inv->fresh();
        };

        $a = $makePostedInvoice('A', 100);
        $b = $makePostedInvoice('B', 100);

        $p1 = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 120,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$p1->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'p9-p1',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$p1->id}/apply-supplier-invoices", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-10',
                'allocations' => [
                    ['supplier_invoice_id' => $a->id, 'amount' => 60],
                    ['supplier_invoice_id' => $b->id, 'amount' => 60],
                ],
            ])
            ->assertStatus(201);

        $svc = app(BillPaymentService::class);
        $this->assertEqualsWithDelta(40.0, $svc->getSupplierInvoiceOutstanding($a->id, $tenant->id, null), 0.02);
        $this->assertEqualsWithDelta(40.0, $svc->getSupplierInvoiceOutstanding($b->id, $tenant->id, null), 0.02);

        $p2 = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 40,
            'payment_date' => '2024-06-11',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$p2->id}/post", [
                'posting_date' => '2024-06-11',
                'idempotency_key' => 'p9-p2',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$p2->id}/apply-supplier-invoices", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-11',
                'allocations' => [
                    ['supplier_invoice_id' => $a->id, 'amount' => 40],
                ],
            ])
            ->assertStatus(201);

        $this->assertEqualsWithDelta(0.0, $svc->getSupplierInvoiceOutstanding($a->id, $tenant->id, null), 0.02);
        $this->assertEqualsWithDelta(40.0, $svc->getSupplierInvoiceOutstanding($b->id, $tenant->id, null), 0.02);
    }

    public function test_over_allocation_and_wrong_supplier_and_draft_bill_blocked(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P9 Val', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments']);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V1',
            'party_types' => ['VENDOR'],
        ]);
        $other = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V2',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);
        $projectOther = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $other->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field Other',
            'status' => 'ACTIVE',
        ]);

        $posted = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'POSTED',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $posted->id,
            'line_no' => 1,
            'description' => 'L',
            'line_total' => 100,
            'tax_amount' => 0,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$posted->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'p9-val-posted',
            ])
            ->assertStatus(201);

        $draft = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'DRAFT',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 50,
            'tax_amount' => 0,
            'total_amount' => 50,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $draft->id,
            'line_no' => 1,
            'description' => 'L',
            'line_total' => 50,
            'tax_amount' => 0,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 40,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'p9-val-p',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-supplier-invoices", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-10',
                'allocations' => [
                    ['supplier_invoice_id' => $posted->id, 'amount' => 50],
                ],
            ])
            ->assertStatus(422);

        $invOther = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $other->id,
            'project_id' => $projectOther->id,
            'reference_no' => 'OTHER',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invOther->id,
            'line_no' => 1,
            'description' => 'L',
            'line_total' => 100,
            'tax_amount' => 0,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invOther->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'p9-val-other-inv',
            ])
            ->assertStatus(201);

        $pOther = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $other->id,
            'direction' => 'OUT',
            'amount' => 100,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$pOther->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'p9-val-po',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$pOther->id}/apply-supplier-invoices", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-10',
                'allocations' => [
                    ['supplier_invoice_id' => $posted->id, 'amount' => 10],
                ],
            ])
            ->assertStatus(404);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-supplier-invoices", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-10',
                'allocations' => [
                    ['supplier_invoice_id' => $draft->id, 'amount' => 10],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_non_asset_source_account_rejected_on_post(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P9 Bad', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments']);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'X',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 200,
            'tax_amount' => 0,
            'total_amount' => 200,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'L',
            'line_total' => 200,
            'tax_amount' => 0,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'p9-bad-inv',
            ])
            ->assertStatus(201);

        $expense = Account::create([
            'tenant_id' => $tenant->id,
            'code' => 'EXP-P9',
            'name' => 'Random expense',
            'type' => 'expense',
            'is_system' => false,
        ]);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 50,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'source_account_id' => $expense->id,
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'p9-bad-p',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(422);
    }
}
