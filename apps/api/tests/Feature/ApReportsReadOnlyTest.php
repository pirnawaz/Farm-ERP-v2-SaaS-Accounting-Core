<?php

namespace Tests\Feature;

use App\Domains\Commercial\Payables\SupplierInvoice;
use App\Domains\Commercial\Payables\SupplierInvoiceLine;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApReportsReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ap_report_endpoints_are_read_only(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project',
            'status' => 'ACTIVE',
            'crop_cycle_id' => $cycle->id,
            'party_id' => null,
        ]);
        $supplierParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'party_types' => ['VENDOR'],
        ]);

        // Canonical AP fixture: posted supplier invoice (reports must read invoices, not deprecated bills).
        $invoice = SupplierInvoice::create([
            'tenant_id' => $tenant->id,
            'party_id' => $supplierParty->id,
            'project_id' => $project->id,
            'reference_no' => 'SINV-RO-1',
            'invoice_date' => '2026-04-01',
            'due_date' => '2026-04-15',
            'currency_code' => 'GBP',
            'payment_terms' => 'CASH',
            'subtotal_amount' => '60.00',
            'tax_amount' => '0.00',
            'total_amount' => '60.00',
            'status' => SupplierInvoice::STATUS_POSTED,
            'posted_at' => now(),
        ]);
        SupplierInvoiceLine::create([
            'tenant_id' => $tenant->id,
            'supplier_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Line',
            'qty' => 1,
            'unit_price' => 60,
            'line_total' => '60.00',
            'tax_amount' => '0.00',
        ]);

        $pgInvoice = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SUPPLIER_INVOICE',
            'source_id' => $invoice->id,
            'posting_date' => '2026-04-01',
            'idempotency_key' => 'fixture:invoice',
        ]);
        $invoice->posting_group_id = $pgInvoice->id;
        $invoice->save();

        $invoiceUpdatedAtBeforeRaw = DB::table('supplier_invoices')->where('id', $invoice->id)->value('updated_at');
        $pgCountBefore = PostingGroup::count();
        $leCountBefore = LedgerEntry::count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/unpaid-bills')
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/aging?as_of=2026-04-15')
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/supplier-ledger?party_id=' . $supplierParty->id . '&from=2026-04-01&to=2026-04-30')
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ap-reports/credit-premium-by-project?from=2026-04-01&to=2026-04-30&project_id=' . $project->id)
            ->assertStatus(200);

        $invoiceUpdatedAtAfterRaw = DB::table('supplier_invoices')->where('id', $invoice->id)->value('updated_at');
        $this->assertEquals($pgCountBefore, PostingGroup::count(), 'Reports must not create posting groups');
        $this->assertEquals($leCountBefore, LedgerEntry::count(), 'Reports must not create ledger entries');
        $this->assertEquals($invoiceUpdatedAtBeforeRaw, $invoiceUpdatedAtAfterRaw, 'Reports must not update invoices');
    }
}

