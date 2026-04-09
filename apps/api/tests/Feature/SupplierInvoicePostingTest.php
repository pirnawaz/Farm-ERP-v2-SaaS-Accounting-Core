<?php

namespace Tests\Feature;

use App\Domains\Accounting\MultiCurrency\ExchangeRate;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvUom;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\PartyFinancialSourceService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierInvoicePostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

    public function test_post_supplier_invoice_creates_single_posting_group_allocations_and_balanced_ledger(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'AP Tenant', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Co',
            'party_types' => ['VENDOR'],
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $uom = InvUom::create(['tenant_id' => $tenant->id, 'code' => 'KG', 'name' => 'Kg']);
        $cat = InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Inputs']);
        $item = InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-1001',
            'invoice_date' => '2024-06-10',
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
            'description' => 'Stock line',
            'item_id' => $item->id,
            'qty' => 10,
            'unit_price' => 40,
            'line_total' => 400,
            'tax_amount' => 0,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 2,
            'description' => 'Service',
            'item_id' => null,
            'qty' => null,
            'unit_price' => null,
            'line_total' => 600,
            'tax_amount' => 0,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-post-1',
            ]);

        $res->assertStatus(201);
        $pgId = $res->json('id');
        $this->assertNotEmpty($pgId);

        $invoice->refresh();
        $this->assertEquals(SupplierInvoice::STATUS_POSTED, $invoice->status);
        $this->assertEquals($pgId, $invoice->posting_group_id);
        $this->assertNotNull($invoice->posted_at);

        $this->assertSame(2, AllocationRow::where('posting_group_id', $pgId)->count());

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(1000.0, $sumDr, 0.02);
        $this->assertEqualsWithDelta(1000.0, $sumCr, 0.02);

        $ap = (new PartyFinancialSourceService)->getSupplierPayableFromGRN($supplier->id, $tenant->id, null, null);
        $this->assertEqualsWithDelta(1000.0, $ap, 0.02);
    }

    public function test_post_is_idempotent_by_idempotency_key(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'AP Tenant 2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

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
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'currency_code' => 'GBP',
            'subtotal_amount' => 50,
            'tax_amount' => 0,
            'total_amount' => 50,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 50,
            'tax_amount' => 0,
        ]);

        $payload = ['posting_date' => '2024-06-15', 'idempotency_key' => 'idem-si-1'];
        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", $payload);
        $r1->assertStatus(201);
        $id1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", $payload);
        $r2->assertStatus(201);
        $this->assertEquals($id1, $r2->json('id'));
    }

    public function test_post_fails_when_crop_cycle_closed(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'AP Closed', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Co',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-CLOSED',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'GBP',
            'subtotal_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 100,
            'tax_amount' => 0,
        ]);

        CropCycle::where('id', $cycle->id)->update(['status' => 'CLOSED']);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-closed',
            ]);

        $res->assertStatus(422);
    }

    public function test_post_supplier_invoice_foreign_currency_balances_in_base(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'AP FX', 'status' => 'active', 'currency_code' => 'USD']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        ExchangeRate::create([
            'tenant_id' => $tenant->id,
            'rate_date' => '2024-06-01',
            'base_currency_code' => 'USD',
            'quote_currency_code' => 'EUR',
            'rate' => 1.25,
            'source' => 'test',
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'EU Vendor',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field FX',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'INV-EUR-1',
            'invoice_date' => '2024-06-10',
            'currency_code' => 'EUR',
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'total_amount' => 80,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Service',
            'item_id' => null,
            'line_total' => 80,
            'tax_amount' => 0,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-fx-1',
            ]);

        $res->assertStatus(201);
        $pgId = $res->json('id');

        $pg = PostingGroup::findOrFail($pgId);
        $this->assertSame('EUR', $pg->currency_code);
        $this->assertSame('USD', $pg->base_currency_code);

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount');
        $this->assertEqualsWithDelta(80.0, $sumDr, 0.02);
        $this->assertEqualsWithDelta(80.0, $sumCr, 0.02);

        $sumDrB = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('debit_amount_base');
        $sumCrB = (float) LedgerEntry::where('posting_group_id', $pgId)->sum('credit_amount_base');
        $this->assertEqualsWithDelta(100.0, $sumDrB, 0.02);
        $this->assertEqualsWithDelta(100.0, $sumCrB, 0.02);
    }

    public function test_post_supplier_invoice_fails_422_when_foreign_currency_missing_rate(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'AP No rate', 'status' => 'active', 'currency_code' => 'USD']);
        SystemAccountsSeeder::runForTenant($tenant->id);

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
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'currency_code' => 'EUR',
            'subtotal_amount' => 40,
            'tax_amount' => 0,
            'total_amount' => 40,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 40,
            'tax_amount' => 0,
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'si-norate',
            ]);

        $res->assertStatus(422);
        $msg = $res->json('errors.exchange_rate.0');
        $this->assertIsString($msg);
        $this->assertStringContainsStringIgnoringCase('exchange rate', $msg);
    }
}
