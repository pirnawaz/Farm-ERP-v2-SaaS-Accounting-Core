<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\InvItem;
use App\Models\InvItemCategory;
use App\Models\InvStore;
use App\Models\InvUom;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;
use App\Models\Module;
use App\Models\TenantModule;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderAndMatchingTest extends TestCase
{
    use RefreshDatabase;

    private function enableInventory(Tenant $tenant): void
    {
        (new ModulesSeeder)->run();
        $m = Module::where('key', 'inventory')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    public function test_po_create_and_approve_and_matching_overbilling_blocked(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active', 'currency_code' => 'GBP']);
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
        InvStore::create(['tenant_id' => $tenant->id, 'name' => 'Main Store', 'type' => 'MAIN', 'is_active' => true]);

        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'status' => 'ACTIVE',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Party',
            'party_types' => ['VENDOR'],
        ]);

        $createPo = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/purchase-orders', [
                'supplier_id' => $supplier->id,
                'po_no' => 'PO-001',
                'po_date' => '2026-04-01',
                'lines' => [
                    ['line_no' => 1, 'item_id' => $item->id, 'qty_ordered' => 10, 'qty_overbill_tolerance' => 0],
                ],
            ]);
        $createPo->assertStatus(201);
        $poId = $createPo->json('id');
        $poLineId = $createPo->json('lines.0.id');

        $approve = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/purchase-orders/{$poId}/approve", []);
        $approve->assertStatus(200);
        $this->assertEquals(PurchaseOrder::STATUS_APPROVED, $approve->json('status'));

        // Draft invoice match can be saved but must not count in PO rollup.
        $invDraft = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => null,
            'cost_center_id' => null,
            'invoice_date' => '2026-04-02',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '70.00',
            'tax_amount' => '0.00',
            'total_amount' => '70.00',
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $draftLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invDraft->id,
            'line_no' => 1,
            'description' => 'Line A',
            'qty' => 7,
            'unit_price' => 10,
            'line_total' => '70.00',
            'tax_amount' => '0.00',
        ]);
        $syncDraft = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invDraft->id}/po-matches", [
                'matches' => [
                    [
                        'supplier_invoice_line_id' => $draftLine->id,
                        'purchase_order_line_id' => $poLineId,
                        'matched_qty' => 7,
                        'matched_amount' => 70,
                    ],
                ],
            ]);
        $syncDraft->assertStatus(200);

        $matching = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/matching");
        $matching->assertStatus(200);
        $this->assertEquals($poLineId, $matching->json('lines.0.purchase_order_line_id'));
        $this->assertEquals('10.000000', $matching->json('lines.0.qty_ordered'));
        $this->assertEquals('0.000000', $matching->json('lines.0.qty_billed'), 'Draft invoice matches must not count');

        // Posted invoice match counts in PO rollup.
        $invPosted = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => null,
            'cost_center_id' => null,
            'invoice_date' => '2026-04-03',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '70.00',
            'tax_amount' => '0.00',
            'total_amount' => '70.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $postedLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invPosted->id,
            'line_no' => 1,
            'description' => 'Line B',
            'qty' => 7,
            'unit_price' => 10,
            'line_total' => '70.00',
            'tax_amount' => '0.00',
        ]);
        $syncPosted = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invPosted->id}/po-matches", [
                'matches' => [
                    [
                        'supplier_invoice_line_id' => $postedLine->id,
                        'purchase_order_line_id' => $poLineId,
                        'matched_qty' => 7,
                        'matched_amount' => 70,
                    ],
                ],
            ]);
        $syncPosted->assertStatus(200);

        $matching2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/matching");
        $matching2->assertStatus(200);
        $this->assertEquals('7.000000', $matching2->json('lines.0.qty_billed'));

        // Overbilling is blocked (7 + 5 > 10).
        $invPosted2 = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => null,
            'cost_center_id' => null,
            'invoice_date' => '2026-04-04',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '50.00',
            'tax_amount' => '0.00',
            'total_amount' => '50.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $postedLine2 = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invPosted2->id,
            'line_no' => 1,
            'description' => 'Line C',
            'qty' => 5,
            'unit_price' => 10,
            'line_total' => '50.00',
            'tax_amount' => '0.00',
        ]);
        $syncOver = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invPosted2->id}/po-matches", [
                'matches' => [
                    [
                        'supplier_invoice_line_id' => $postedLine2->id,
                        'purchase_order_line_id' => $poLineId,
                        'matched_qty' => 5,
                        'matched_amount' => 50,
                    ],
                ],
            ]);
        $syncOver->assertStatus(422);

        // Deprecated supplier bill matches must not affect canonical PO billed rollup.
        $pgDeprecated = \App\Models\PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => null,
            'source_type' => 'SUPPLIER_BILL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-04-05',
            'idempotency_key' => 'fixture:deprecated-bill',
        ]);
        $billDeprecated = \App\Models\SupplierBill::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-04-05',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'status' => \App\Models\SupplierBill::STATUS_POSTED,
            'subtotal_cash_amount' => '0.00',
            'credit_premium_total' => '0.00',
            'grand_total' => '10.00',
            'posting_group_id' => $pgDeprecated->id,
            'posting_date' => '2026-04-05',
            'posted_at' => now(),
            'payment_status' => 'UNPAID',
            'paid_amount' => '0.00',
            'outstanding_amount' => '10.00',
        ]);
        $billLineDeprecated = $billDeprecated->lines()->create([
            'tenant_id' => $tenant->id,
            'line_no' => 1,
            'description' => 'Deprecated Bill Line',
            'qty' => 1,
            'cash_unit_price' => 10,
            'credit_unit_price' => null,
            'base_cash_amount' => '10.00',
            'selected_unit_price' => '10.000000',
            'credit_premium_amount' => '0.00',
            'line_total' => '10.00',
        ]);
        \App\Models\SupplierBillLineMatch::create([
            'tenant_id' => $tenant->id,
            'supplier_bill_line_id' => $billLineDeprecated->id,
            'purchase_order_line_id' => $poLineId,
            'grn_line_id' => null,
            'matched_qty' => 1,
            'matched_amount' => 10,
        ]);

        $matching3 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/matching");
        $matching3->assertStatus(200);
        $this->assertEquals('7.000000', $matching3->json('lines.0.qty_billed'), 'Deprecated supplier bills must be ignored');
    }

    public function test_prepare_invoice_endpoint_returns_remaining_qty_and_unit_price_and_party(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableInventory($tenant);

        $supplierParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Party',
            'party_types' => ['VENDOR'],
        ]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'status' => 'ACTIVE',
            'party_id' => $supplierParty->id,
        ]);

        $createPo = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/purchase-orders', [
                'supplier_id' => $supplier->id,
                'po_no' => 'PO-INV-001',
                'po_date' => '2026-04-01',
                'lines' => [
                    ['line_no' => 1, 'description' => 'Item', 'qty_ordered' => 10, 'qty_overbill_tolerance' => 0, 'expected_unit_cost' => 11.5],
                ],
            ]);
        $createPo->assertStatus(201);
        $poId = $createPo->json('id');
        $poLineId = $createPo->json('lines.0.id');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/purchase-orders/{$poId}/approve", [])
            ->assertStatus(200);

        // Invoiced qty should count only POSTED/PAID supplier invoices.
        $invDraft = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplierParty->id,
            'project_id' => null,
            'cost_center_id' => null,
            'invoice_date' => '2026-04-02',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '20.00',
            'tax_amount' => '0.00',
            'total_amount' => '20.00',
            'status' => SupplierInvoice::STATUS_DRAFT,
        ]);
        $draftLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invDraft->id,
            'line_no' => 1,
            'description' => 'Draft',
            'qty' => 2,
            'unit_price' => 10,
            'line_total' => '20.00',
            'tax_amount' => '0.00',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invDraft->id}/po-matches", [
                'matches' => [
                    ['supplier_invoice_line_id' => $draftLine->id, 'purchase_order_line_id' => $poLineId, 'matched_qty' => 2, 'matched_amount' => 20],
                ],
            ])
            ->assertStatus(200);

        $invPosted = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplierParty->id,
            'project_id' => null,
            'cost_center_id' => null,
            'invoice_date' => '2026-04-03',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '70.00',
            'tax_amount' => '0.00',
            'total_amount' => '70.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $postedLine = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invPosted->id,
            'line_no' => 1,
            'description' => 'Posted',
            'qty' => 7,
            'unit_price' => 10,
            'line_total' => '70.00',
            'tax_amount' => '0.00',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invPosted->id}/po-matches", [
                'matches' => [
                    ['supplier_invoice_line_id' => $postedLine->id, 'purchase_order_line_id' => $poLineId, 'matched_qty' => 7, 'matched_amount' => 70],
                ],
            ])
            ->assertStatus(200);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/prepare-invoice");
        $res->assertStatus(200);
        $this->assertEquals($supplierParty->id, $res->json('party_id'));
        $this->assertEquals('GBP', $res->json('currency_code'));
        $this->assertEquals($poLineId, $res->json('lines.0.purchase_order_line_id'));
        $this->assertEquals('10.000000', $res->json('lines.0.qty_ordered'));
        $this->assertEquals('7.000000', $res->json('lines.0.qty_invoiced'));
        $this->assertEquals('3.000000', $res->json('lines.0.remaining_qty'));
        $this->assertEquals('11.500000', $res->json('lines.0.unit_price'));
    }
}

