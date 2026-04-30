<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Module;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalProcurementApEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function enableModules(Tenant $tenant, array $keys): void
    {
        (new ModulesSeeder)->run();
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

    public function test_canonical_procurement_to_ap_flow_end_to_end(): void
    {
        $tenant = Tenant::create(['name' => 'E2E', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['treasury_payments', 'reports']);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);

        // 1) Create canonical supplier party.
        $supplierParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Party',
            'party_types' => ['VENDOR'],
        ]);

        // Purchase Orders are still keyed to Supplier (deprecated master). Keep it linked to party for coherence.
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'status' => 'ACTIVE',
            'party_id' => $supplierParty->id,
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplierParty->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Project',
            'status' => 'ACTIVE',
        ]);

        // 2) Create purchase order (1 line qty 10).
        $poCreate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/purchase-orders', [
                'supplier_id' => $supplier->id,
                'po_no' => 'PO-E2E-1',
                'po_date' => '2026-04-01',
                'lines' => [
                    ['line_no' => 1, 'description' => 'Item', 'qty_ordered' => 10, 'qty_overbill_tolerance' => 0],
                ],
            ]);
        $poCreate->assertStatus(201);
        $poId = $poCreate->json('id');
        $poLineId = $poCreate->json('lines.0.id');

        // 3) Approve purchase order.
        $poApprove = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/purchase-orders/{$poId}/approve", []);
        $poApprove->assertStatus(200);
        $this->assertEquals('APPROVED', $poApprove->json('status'));

        // 4–5) Create supplier invoice with CREDIT terms and premium fields.
        $invCreate = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/supplier-invoices', [
                'party_id' => $supplierParty->id,
                'project_id' => $project->id,
                'invoice_date' => '2026-04-02',
                'currency_code' => 'GBP',
                'payment_terms' => 'CREDIT',
                'subtotal_amount' => 120,
                'tax_amount' => 0,
                'total_amount' => 120,
                'lines' => [
                    [
                        'line_no' => 1,
                        'description' => 'Inputs',
                        'qty' => 10,
                        'cash_unit_price' => 10,
                        'credit_unit_price' => 12,
                        'selected_unit_price' => 12,
                        'base_cash_amount' => 100,
                        'credit_premium_amount' => 20,
                        'line_total' => 120,
                        'tax_amount' => 0,
                    ],
                ],
            ]);
        $invCreate->assertStatus(201);
        $invoiceId = $invCreate->json('id');
        $invoiceLineId = $invCreate->json('lines.0.id');
        $this->assertEquals('DRAFT', $invCreate->json('status'));
        $this->assertEquals('CREDIT', $invCreate->json('payment_terms'));

        // 6) Link invoice line to PO line (draft can be saved).
        $syncDraftMatch = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/supplier-invoices/{$invoiceId}/po-matches", [
                'matches' => [
                    [
                        'supplier_invoice_line_id' => $invoiceLineId,
                        'purchase_order_line_id' => $poLineId,
                        'matched_qty' => 10,
                        'matched_amount' => 120,
                    ],
                ],
            ]);
        $syncDraftMatch->assertStatus(200);

        // Draft invoice match must NOT count as invoiced in PO rollup.
        $poMatchingBeforePost = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/matching");
        $poMatchingBeforePost->assertStatus(200);
        $this->assertEquals('0.000000', $poMatchingBeforePost->json('lines.0.qty_billed'));

        // 7) Post supplier invoice.
        $ledgerCountBefore = LedgerEntry::where('tenant_id', $tenant->id)->count();
        $post = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-invoices/{$invoiceId}/post", [
                'posting_date' => '2026-04-02',
                'idempotency_key' => 'e2e-post-1',
            ]);
        $post->assertStatus(201);

        // 8) After posting, PO rollup must count invoiced qty.
        $poMatchingAfterPost = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/purchase-orders/{$poId}/matching");
        $poMatchingAfterPost->assertStatus(200);
        $this->assertEquals('10.000000', $poMatchingAfterPost->json('lines.0.qty_billed'));

        // 5/6) Posting ledger entries must be balanced and premium must be separate allocation.
        $newLedger = LedgerEntry::where('tenant_id', $tenant->id)->orderBy('created_at')->skip($ledgerCountBefore)->get();
        $this->assertGreaterThan(0, $newLedger->count(), 'Posting should create ledger entries');
        $sumDebit = (float) $newLedger->sum('debit_amount');
        $sumCredit = (float) $newLedger->sum('credit_amount');
        $this->assertEqualsWithDelta($sumDebit, $sumCredit, 0.01, 'Ledger entries must be balanced');

        // 9) Credit Premium report includes this invoice (canonical allocation rows).
        $report = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/credit-premium-by-project?from=2026-04-01&to=2026-04-30&project_id=' . $project->id);
        $report->assertStatus(200);
        $rows = $report->json('rows');
        $this->assertNotEmpty($rows);
        $amounts = array_map(fn ($r) => (string) ($r['credit_premium_amount'] ?? ''), $rows);
        $this->assertContains('20.00', $amounts);

        // 10) Post and apply supplier payment (canonical flow).
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplierParty->id,
            'direction' => 'OUT',
            'amount' => 120,
            'payment_date' => '2026-04-03',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2026-04-03',
                'idempotency_key' => 'e2e-pay-1',
                'crop_cycle_id' => $cycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-supplier-invoices", [
                'mode' => 'FIFO',
                'allocation_date' => '2026-04-03',
            ])
            ->assertStatus(201);

        // 11) Outstanding becomes 0.00 (or very close).
        $invShow = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/supplier-invoices/{$invoiceId}");
        $invShow->assertStatus(200);
        $this->assertEquals('0.00', $invShow->json('outstanding_amount'));

        // Ensure deprecated AP path not used in this canonical flow.
        $this->assertSame(0, (int) DB::table('supplier_bills')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, (int) DB::table('supplier_payments')->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, (int) DB::table('supplier_bill_line_matches')->where('tenant_id', $tenant->id)->count());
    }
}

