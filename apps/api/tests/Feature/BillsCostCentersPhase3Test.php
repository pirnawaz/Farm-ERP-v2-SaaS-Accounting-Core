<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Exceptions\PostedSourceDocumentImmutableException;
use App\Models\AllocationRow;
use App\Models\CostCenter;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillsCostCentersPhase3Test extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_schema_has_cost_centers_and_bill_columns(): void
    {
        $this->assertTrue(Schema::hasTable('cost_centers'));
        $this->assertTrue(Schema::hasColumns('supplier_invoices', ['cost_center_id', 'due_date']));
        $this->assertTrue(Schema::hasColumn('allocation_rows', 'cost_center_id'));
    }

    public function test_core_tables_not_dropped(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_invoices'));
        $this->assertTrue(Schema::hasTable('supplier_invoice_lines'));
        $this->assertTrue(Schema::hasTable('posting_groups'));
        $this->assertTrue(Schema::hasTable('allocation_rows'));
    }

    public function test_cost_center_crud_and_list(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'CC T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $c = $this->withHeaders($this->headers($tenant))->postJson('/api/cost-centers', [
            'name' => 'Farm HQ',
            'code' => 'HQ',
            'status' => CostCenter::STATUS_ACTIVE,
            'description' => 'Overhead',
        ])->assertCreated()->json();

        $this->assertSame('Farm HQ', $c['name']);

        $this->withHeaders($this->headers($tenant))->putJson('/api/cost-centers/'.$c['id'], [
            'name' => 'Farm HQ Updated',
        ])->assertOk();

        $list = $this->withHeaders($this->headers($tenant))->getJson('/api/cost-centers')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame('Farm HQ Updated', $list[0]['name']);
    }

    public function test_inactive_cost_center_rejected_on_bill_create(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'CC T2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old',
            'code' => 'OLD',
            'status' => CostCenter::STATUS_INACTIVE,
        ]);

        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor',
            'party_types' => ['VENDOR'],
        ]);

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'invoice_date' => '2024-03-01',
            'total_amount' => 100,
            'lines' => [
                ['description' => 'Electric', 'line_total' => 100, 'tax_amount' => 0],
            ],
        ])->assertStatus(422);
    }

    public function test_draft_farm_bill_create_and_post_creates_balanced_posting_and_cost_center_allocations(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'code' => 'ADM',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);

        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Utility Co',
            'party_types' => ['VENDOR'],
        ]);

        $draft = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'UTIL-1',
            'invoice_date' => '2024-03-15',
            'due_date' => '2024-04-01',
            'total_amount' => 250.50,
            'subtotal_amount' => 250.50,
            'tax_amount' => 0,
            'lines' => [
                ['description' => 'Electricity', 'line_total' => 250.50, 'tax_amount' => 0],
            ],
        ])->assertCreated()->json();

        $this->assertSame('DRAFT', $draft['status']);
        $this->assertNull(PostingGroup::where('source_id', $draft['id'])->where('source_type', 'SUPPLIER_INVOICE')->first());

        $post = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$draft['id'].'/post', [
            'posting_date' => '2024-03-20',
            'idempotency_key' => 'farm-bill-1',
        ])->assertCreated()->json();

        $pg = PostingGroup::find($post['id']);
        $this->assertNotNull($pg);
        $this->assertNull($pg->crop_cycle_id);

        $rows = AllocationRow::where('posting_group_id', $pg->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->project_id);
        $this->assertSame($cc->id, $rows[0]->cost_center_id);
        $this->assertSame('SUPPLIER_AP', $rows[0]->allocation_type);

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('credit_amount');
        $this->assertEqualsWithDelta(250.50, $sumDr, 0.02);
        $this->assertEqualsWithDelta(250.50, $sumCr, 0.02);

        $detail = $this->withHeaders($this->headers($tenant))->getJson('/api/supplier-invoices/'.$draft['id'])->assertOk()->json();
        $this->assertSame('farm_overhead', $detail['billing_scope']);
    }

    public function test_post_rejects_without_project_or_cost_center(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);

        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'project_id' => null,
            'cost_center_id' => null,
            'reference_no' => 'X',
            'invoice_date' => '2024-01-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $inv->id,
            'line_no' => 1,
            'description' => 'Line',
            'line_total' => 10,
            'tax_amount' => 0,
        ]);

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv->id.'/post', [
            'posting_date' => '2024-01-10',
            'idempotency_key' => 'orphan-1',
        ])->assertStatus(422);
    }

    public function test_post_rejects_both_project_and_cost_center(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T3', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);
        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);

        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'cost_center_id' => $cc->id,
            'invoice_date' => '2024-01-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $inv->id,
            'line_no' => 1,
            'description' => 'Line',
            'line_total' => 10,
            'tax_amount' => 0,
        ]);

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv->id.'/post', [
            'posting_date' => '2024-01-10',
            'idempotency_key' => 'both-1',
        ])->assertStatus(422);
    }

    public function test_farm_bill_rejects_inventory_line(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T4', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);

        $uom = \App\Models\InvUom::create(['tenant_id' => $tenant->id, 'code' => 'U', 'name' => 'U']);
        $cat = \App\Models\InvItemCategory::create(['tenant_id' => $tenant->id, 'name' => 'Cat']);
        $item = \App\Models\InvItem::create([
            'tenant_id' => $tenant->id,
            'name' => 'Item',
            'uom_id' => $uom->id,
            'category_id' => $cat->id,
            'valuation_method' => 'WAC',
            'is_active' => true,
        ]);

        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'invoice_date' => '2024-01-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 10,
            'tax_amount' => 0,
            'total_amount' => 10,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $inv->id,
            'line_no' => 1,
            'description' => 'Stock',
            'item_id' => $item->id,
            'qty' => 1,
            'unit_price' => 10,
            'line_total' => 10,
            'tax_amount' => 0,
        ]);

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv->id.'/post', [
            'posting_date' => '2024-01-10',
            'idempotency_key' => 'stock-cc-1',
        ])->assertStatus(422);
    }

    public function test_posted_bill_immutable_and_idempotent_repost(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T5', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);

        $draft = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'invoice_date' => '2024-02-01',
            'total_amount' => 50,
            'lines' => [['description' => 'Internet', 'line_total' => 50]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$draft['id'].'/post', [
            'posting_date' => '2024-02-05',
            'idempotency_key' => 'immo-1',
        ])->assertCreated();

        $inv = SupplierInvoice::find($draft['id']);
        $this->expectException(PostedSourceDocumentImmutableException::class);
        $inv->update(['notes' => 'nope']);
    }

    public function test_billing_scope_filter_farm(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Bill T6', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);
        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);

        SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'invoice_date' => '2024-01-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 1,
            'tax_amount' => 0,
            'total_amount' => 1,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'project_id' => null,
            'cost_center_id' => $cc->id,
            'invoice_date' => '2024-01-01',
            'currency_code' => 'GBP',
            'subtotal_amount' => 2,
            'tax_amount' => 0,
            'total_amount' => 2,
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);

        $farm = $this->withHeaders($this->headers($tenant))->getJson('/api/supplier-invoices?billing_scope=farm')->assertOk()->json();
        $this->assertCount(1, $farm);
        $this->assertSame(2.0, (float) $farm[0]['total_amount']);
    }
}
