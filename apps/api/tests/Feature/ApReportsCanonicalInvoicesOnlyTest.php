<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApReportsCanonicalInvoicesOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_credit_premium_report_ignores_supplier_bills(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier Party',
            'party_types' => ['SUPPLIER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);

        // Create a posted supplier invoice + posting group and a premium allocation row.
        $inv = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'project_id' => $project->id,
            'invoice_date' => '2026-04-01',
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'subtotal_amount' => '120.00',
            'tax_amount' => '0.00',
            'total_amount' => '120.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        $pgInv = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $inv->id,
            'posting_date' => '2026-04-01',
            'idempotency_key' => 'fixture:inv',
        ]);
        $inv->posting_group_id = $pgInv->id;
        $inv->save();
        $line = SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $inv->id,
            'line_no' => 1,
            'description' => 'L',
            'qty' => 1,
            'unit_price' => 120,
            'line_total' => '120.00',
            'tax_amount' => '0.00',
            'cash_unit_price' => 100,
            'credit_unit_price' => 120,
            'selected_unit_price' => 120,
            'base_cash_amount' => '100.00',
            'credit_premium_amount' => '20.00',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgInv->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'SUPPLIER_INVOICE_CREDIT_PREMIUM',
            'amount' => '20.00',
            'currency_code' => 'GBP',
            'base_currency_code' => 'GBP',
            'fx_rate' => 1,
            'amount_base' => '20.00',
            'rule_snapshot' => [
                'source_type' => 'SUPPLIER_INVOICE',
                'supplier_invoice_id' => $inv->id,
                'supplier_invoice_line_id' => $line->id,
            ],
        ]);

        // Create a supplier bill posting group + bill allocations (deprecated path) — must be ignored by canonical report.
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Supplier', 'status' => 'ACTIVE', 'party_id' => $party->id]);
        $pgBill = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SUPPLIER_BILL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2026-04-01',
            'idempotency_key' => 'fixture:bill',
        ]);
        $bill = SupplierBill::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-04-01',
            'currency_code' => 'GBP',
            'payment_terms' => 'CREDIT',
            'status' => SupplierBill::STATUS_POSTED,
            'subtotal_cash_amount' => '50.00',
            'credit_premium_total' => '10.00',
            'grand_total' => '60.00',
            'posting_group_id' => $pgBill->id,
            'posting_date' => '2026-04-01',
            'posted_at' => now(),
            'payment_status' => 'UNPAID',
            'paid_amount' => '0.00',
            'outstanding_amount' => '60.00',
        ]);
        $billLine = SupplierBillLine::create([
            'tenant_id' => $tenant->id,
            'supplier_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => 'Deprecated',
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'cost_category' => 'OTHER',
            'qty' => 1,
            'cash_unit_price' => 50,
            'credit_unit_price' => 60,
            'base_cash_amount' => '50.00',
            'selected_unit_price' => '60.000000',
            'credit_premium_amount' => '10.00',
            'line_total' => '60.00',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pgBill->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'SUPPLIER_BILL_CREDIT_PREMIUM',
            'amount' => '10.00',
            'currency_code' => 'GBP',
            'base_currency_code' => 'GBP',
            'fx_rate' => 1,
            'amount_base' => '10.00',
            'rule_snapshot' => [
                'source_type' => 'SUPPLIER_BILL',
                'supplier_bill_id' => $bill->id,
                'supplier_bill_line_id' => $billLine->id,
            ],
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/credit-premium-by-project?from=2026-04-01&to=2026-04-30');

        $res->assertStatus(200);
        $rows = $res->json('rows');
        $this->assertNotEmpty($rows);

        // Must include the canonical invoice premium (20.00).
        $amounts = array_map(fn ($r) => (string) ($r['credit_premium_amount'] ?? ''), $rows);
        $this->assertContains('20.00', $amounts);

        // Must not include deprecated bill premium (10.00) because we filter by source_type + allocation_type.
        $this->assertNotContains('10.00', $amounts);
    }
}

