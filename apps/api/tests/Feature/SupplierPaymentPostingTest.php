<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierBillPaymentAllocation;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPaymentPostingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantSupplierAndPostedBill(string $name, float $billTotal = 60.0): array
    {
        $tenant = Tenant::create(['name' => $name, 'status' => 'active', 'currency_code' => 'GBP']);
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
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier',
            'status' => 'ACTIVE',
            'party_id' => null,
        ]);

        // Minimal "posted bill" fixture: we don't assert bill posting internals here.
        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'SUPPLIER_BILL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
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
            'subtotal_cash_amount' => number_format($billTotal - 10, 2, '.', ''),
            'credit_premium_total' => '10.00',
            'grand_total' => number_format($billTotal, 2, '.', ''),
            'posting_group_id' => $pg->id,
            'posting_date' => '2026-04-01',
            'posted_at' => now(),
            'payment_status' => 'UNPAID',
            'paid_amount' => '0.00',
            'outstanding_amount' => number_format($billTotal, 2, '.', ''),
        ]);

        SupplierBillLine::create([
            'tenant_id' => $tenant->id,
            'supplier_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => 'Line',
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'cost_category' => 'OTHER',
            'qty' => 1,
            'cash_unit_price' => 50,
            'credit_unit_price' => 60,
            'base_cash_amount' => number_format($billTotal - 10, 2, '.', ''),
            'selected_unit_price' => '60.000000',
            'credit_premium_amount' => '10.00',
            'line_total' => number_format($billTotal, 2, '.', ''),
        ]);

        return compact('tenant', 'cycle', 'project', 'supplier', 'bill');
    }

    public function test_post_supplier_payment_creates_balanced_ledger_and_updates_bill_status(): void
    {
        $data = $this->seedTenantSupplierAndPostedBill('T-pay-1', 60.0);
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $bill = $data['bill'];

        $payment = SupplierPayment::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'payment_date' => '2026-04-10',
            'payment_method' => 'CASH',
            'status' => SupplierPayment::STATUS_DRAFT,
            'total_amount' => '60.00',
        ]);
        SupplierBillPaymentAllocation::create([
            'tenant_id' => $tenant->id,
            'supplier_payment_id' => $payment->id,
            'supplier_bill_id' => $bill->id,
            'amount_applied' => '60.00',
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-payments/{$payment->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sbpay-1',
            ]);
        $res->assertStatus(201);

        $payment->refresh();
        $this->assertEquals(SupplierPayment::STATUS_POSTED, $payment->status);
        $this->assertNotNull($payment->posting_group_id);

        $ap = Account::where('tenant_id', $tenant->id)->where('code', 'AP')->first();
        $cash = Account::where('tenant_id', $tenant->id)->where('code', 'CASH')->first();
        $this->assertNotNull($ap);
        $this->assertNotNull($cash);

        $drAp = (float) LedgerEntry::where('posting_group_id', $payment->posting_group_id)->where('account_id', $ap->id)->sum('debit_amount');
        $crCash = (float) LedgerEntry::where('posting_group_id', $payment->posting_group_id)->where('account_id', $cash->id)->sum('credit_amount');
        $this->assertEquals(60, $drAp);
        $this->assertEquals(60, $crCash);

        $bill->refresh();
        $this->assertEquals(SupplierBill::STATUS_PAID, $bill->status);
        $this->assertEquals('PAID', $bill->payment_status);
        $this->assertEquals('60.00', $bill->paid_amount);
        $this->assertEquals('0.00', $bill->outstanding_amount);
    }

    public function test_payment_post_is_idempotent(): void
    {
        $data = $this->seedTenantSupplierAndPostedBill('T-pay-2', 60.0);
        $tenant = $data['tenant'];
        $supplier = $data['supplier'];
        $bill = $data['bill'];

        $payment = SupplierPayment::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'payment_date' => '2026-04-10',
            'payment_method' => 'CASH',
            'status' => SupplierPayment::STATUS_DRAFT,
            'total_amount' => '60.00',
        ]);
        SupplierBillPaymentAllocation::create([
            'tenant_id' => $tenant->id,
            'supplier_payment_id' => $payment->id,
            'supplier_bill_id' => $bill->id,
            'amount_applied' => '60.00',
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-payments/{$payment->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sbpay-2',
            ])
            ->assertStatus(201);

        $payment->refresh();
        $pgId = $payment->posting_group_id;
        $ledgerCountBefore = LedgerEntry::where('posting_group_id', $pgId)->count();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-payments/{$payment->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sbpay-2',
            ])
            ->assertStatus(201);

        $this->assertEquals($ledgerCountBefore, LedgerEntry::where('posting_group_id', $pgId)->count());
    }
}

