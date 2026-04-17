<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierCreditNote;
use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\InvGrn;
use App\Models\InvGrnLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\BillPaymentService;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase8ApMaturityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        TenantContext::clear();
        parent::setUp();
    }

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

    private function seedTenantWithSupplierProject(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P8', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);
        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $supplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor P8',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
            'status' => 'ACTIVE',
        ]);

        return compact('tenant', 'supplier', 'project', 'cycle');
    }

    public function test_sync_matches_links_bill_line_to_grn_line_and_blocks_overmatch(): void
    {
        extract($this->seedTenantWithSupplierProject());
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
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'code' => 'M1',
            'type' => 'MAIN',
            'is_active' => true,
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-P8-1',
            'supplier_party_id' => $supplier->id,
            'store_id' => $store->id,
            'doc_date' => '2024-05-01',
            'status' => 'DRAFT',
        ]);
        $grnLine = InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 10,
            'unit_cost' => 50,
            'line_total' => '500',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-05-02',
                'idempotency_key' => 'grn-p8-1',
            ])->assertStatus(201);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'reference_no' => 'BILL-P8',
            'invoice_date' => '2024-05-10',
            'due_date' => '2024-06-01',
            'currency_code' => 'GBP',
            'total_amount' => 400,
            'subtotal_amount' => 400,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $invLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 400,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoice->id}/matches", [
                'matches' => [[
                    'supplier_invoice_line_id' => $invLine->id,
                    'grn_line_id' => $grnLine->id,
                    'matched_qty' => 4,
                    'matched_amount' => 400,
                ]],
            ])->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoice->id}/matches", [
                'matches' => [[
                    'supplier_invoice_line_id' => $invLine->id,
                    'grn_line_id' => $grnLine->id,
                    'matched_qty' => 4,
                    'matched_amount' => 501,
                ]],
            ])->assertStatus(422);

        $otherTenant = Tenant::create(['name' => 'T2', 'status' => 'active', 'currency_code' => 'GBP']);
        $this->withHeader('X-Tenant-Id', $otherTenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoice->id}/matches", ['matches' => []])
            ->assertStatus(404);
    }

    public function test_sync_matches_blocks_wrong_supplier(): void
    {
        extract($this->seedTenantWithSupplierProject());
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
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'code' => 'M1',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
        $otherSupplier = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'party_types' => ['VENDOR'],
        ]);

        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-P8-2',
            'supplier_party_id' => $otherSupplier->id,
            'store_id' => $store->id,
            'doc_date' => '2024-05-01',
            'status' => 'DRAFT',
        ]);
        $grnLine = InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 1,
            'unit_cost' => 100,
            'line_total' => '100',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-05-02',
                'idempotency_key' => 'grn-p8-2',
            ])->assertStatus(201);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'invoice_date' => '2024-05-10',
            'currency_code' => 'GBP',
            'total_amount' => 50,
            'subtotal_amount' => 50,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $invLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 50,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoice->id}/matches", [
                'matches' => [[
                    'supplier_invoice_line_id' => $invLine->id,
                    'grn_line_id' => $grnLine->id,
                    'matched_qty' => 1,
                    'matched_amount' => 50,
                ]],
            ])->assertStatus(422);
    }

    public function test_supplier_credit_note_post_reduces_outstanding_without_mutating_bill(): void
    {
        extract($this->seedTenantWithSupplierProject());
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
            'invoice_date' => '2024-05-10',
            'currency_code' => 'GBP',
            'total_amount' => 300,
            'subtotal_amount' => 300,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 300,
            'item_id' => $item->id,
            'qty' => 3,
            'unit_price' => 100,
            'tax_amount' => 0,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-05-12',
                'idempotency_key' => 'si-p8-cn',
            ])->assertStatus(201);

        $bps = app(BillPaymentService::class);
        $this->assertEqualsWithDelta(300.0, $bps->getSupplierInvoiceOutstanding($invoice->id, $tenant->id), 0.02);

        $cn = SupplierCreditNote::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'supplier_invoice_id' => $invoice->id,
            'credit_date' => '2024-05-20',
            'currency_code' => 'GBP',
            'total_amount' => 100,
            'status' => SupplierCreditNote::STATUS_DRAFT,
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-credit-notes/{$cn->id}/post", [
                'posting_date' => '2024-05-21',
                'idempotency_key' => 'cn-p8-1',
            ])->assertStatus(201);

        $invoice->refresh();
        $this->assertEquals(300.0, (float) $invoice->total_amount);
        $this->assertEqualsWithDelta(200.0, $bps->getSupplierInvoiceOutstanding($invoice->id, $tenant->id), 0.02);

        $pg = PostingGroup::where('tenant_id', $tenant->id)->where('source_type', 'SUPPLIER_CREDIT_NOTE')->firstOrFail();
        $this->assertSame(1, AllocationRow::where('posting_group_id', $pg->id)->where('allocation_type', 'SUPPLIER_CREDIT')->count());
        $ap = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('debit_amount');
        $this->assertEqualsWithDelta(100.0, $ap, 0.02);
    }

    public function test_ap_supplier_outstanding_and_ap_ageing_exclude_drafts(): void
    {
        extract($this->seedTenantWithSupplierProject());
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

        SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'invoice_date' => '2024-05-10',
            'due_date' => '2024-05-15',
            'currency_code' => 'GBP',
            'total_amount' => 999,
            'subtotal_amount' => 999,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);

        $posted = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'invoice_date' => '2024-05-10',
            'due_date' => '2024-05-15',
            'currency_code' => 'GBP',
            'total_amount' => 200,
            'subtotal_amount' => 200,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $posted->id,
            'line_no' => 1,
            'line_total' => 200,
            'item_id' => $item->id,
            'qty' => 2,
            'unit_price' => 100,
            'tax_amount' => 0,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$posted->id}/post", [
                'posting_date' => '2024-05-12',
                'idempotency_key' => 'si-p8-age',
            ])->assertStatus(201);

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/ap-supplier-outstanding?as_of=2024-06-01')
            ->assertOk()
            ->json('rows');
        $this->assertNotEmpty($r);
        $row = $r[0];
        $this->assertEquals('200.00', $row['open_supplier_invoice_outstanding']);

        $age = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/ap-ageing?as_of=2024-06-01')
            ->assertOk()
            ->json();
        $this->assertNotEmpty($age['rows']);
    }

    public function test_supplier_invoice_show_includes_match_summary_and_outstanding(): void
    {
        extract($this->seedTenantWithSupplierProject());
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
        $store = InvStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'code' => 'M1',
            'type' => 'MAIN',
            'is_active' => true,
        ]);
        $grn = InvGrn::create([
            'tenant_id' => $tenant->id,
            'doc_no' => 'GRN-P8-3',
            'supplier_party_id' => $supplier->id,
            'store_id' => $store->id,
            'doc_date' => '2024-05-01',
            'status' => 'DRAFT',
        ]);
        $grnLine = InvGrnLine::create([
            'tenant_id' => $tenant->id,
            'grn_id' => $grn->id,
            'item_id' => $item->id,
            'qty' => 10,
            'unit_cost' => 10,
            'line_total' => '100',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/v1/inventory/grns/{$grn->id}/post", [
                'posting_date' => '2024-05-02',
                'idempotency_key' => 'grn-p8-3',
            ])->assertStatus(201);

        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplier->id,
            'project_id' => $project->id,
            'invoice_date' => '2024-05-10',
            'currency_code' => 'GBP',
            'total_amount' => 80,
            'subtotal_amount' => 80,
            'tax_amount' => 0,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $invLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'line_total' => 80,
            'tax_amount' => 0,
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoice->id}/matches", [
                'matches' => [[
                    'supplier_invoice_line_id' => $invLine->id,
                    'grn_line_id' => $grnLine->id,
                    'matched_qty' => 8,
                    'matched_amount' => 80,
                ]],
            ])->assertOk();

        $this->withHeader('X-Tenant-Id', $tenant->id)->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoice->id}/post", [
                'posting_date' => '2024-05-12',
                'idempotency_key' => 'si-p8-show',
            ])->assertStatus(201);

        $payload = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson("/api/supplier-invoices/{$invoice->id}")
            ->assertOk()
            ->json();

        $this->assertEquals(80.0, (float) $payload['ap_match_summary']['matched_amount']);
        $this->assertEquals(0.0, (float) $payload['ap_match_summary']['unmatched_amount']);
        $this->assertEqualsWithDelta(80.0, (float) $payload['outstanding_amount'], 0.02);
    }
}
