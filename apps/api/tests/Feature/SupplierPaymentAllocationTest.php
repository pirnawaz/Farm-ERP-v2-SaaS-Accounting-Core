<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SupplierPaymentAllocation;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\BillPaymentService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    private function enableTreasury(Tenant $tenant): void
    {
        $m = Module::where('key', 'treasury_payments')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_apply_supplier_invoice_allocation_tracks_outstanding_and_skips_ledger(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Alloc Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableTreasury($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor',
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
            'reference_no' => 'SINV-1',
            'invoice_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 1000,
            'tax_amount' => 0,
            'total_amount' => 1000,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Service',
            'line_total' => 1000,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'sinv-alloc-1',
            ])
            ->assertStatus(201);

        $invoice->refresh();
        $this->assertEquals(SupplierInvoice::STATUS_POSTED, $invoice->status);

        $svc = app(BillPaymentService::class);
        $this->assertEqualsWithDelta(1000.0, $svc->getSupplierInvoiceOutstanding($invoice->id, $tenant->id, null), 0.02);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'direction' => 'OUT',
            'amount' => 300,
            'payment_date' => '2024-06-10',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'pmt-si-alloc-1',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $ledgerBefore = LedgerEntry::where('tenant_id', $tenant->id)->count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-supplier-invoices", [
                'mode' => 'FIFO',
                'allocation_date' => '2024-06-10',
            ])
            ->assertStatus(201);

        $this->assertEquals($ledgerBefore, LedgerEntry::where('tenant_id', $tenant->id)->count(), 'Apply must not create ledger lines');

        $this->assertEquals(1, SupplierPaymentAllocation::where('payment_id', $payment->id)->where('status', 'ACTIVE')->count());
        $this->assertEqualsWithDelta(700.0, $svc->getSupplierInvoiceOutstanding($invoice->id, $tenant->id, null), 0.02);

        $summary = $svc->getPaymentAllocationSummary($tenant->id, $payment->id);
        $this->assertEquals('300.00', $summary['applied_amount']);
        $this->assertEquals('0.00', $summary['unapplied_amount']);
        $this->assertCount(1, $summary['supplier_invoice_allocations']);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-supplier-invoices")
            ->assertStatus(200);

        $this->assertEquals(0, SupplierPaymentAllocation::where('payment_id', $payment->id)->where('status', 'ACTIVE')->count());
        $this->assertEqualsWithDelta(1000.0, $svc->getSupplierInvoiceOutstanding($invoice->id, $tenant->id, null), 0.02);
    }
}
