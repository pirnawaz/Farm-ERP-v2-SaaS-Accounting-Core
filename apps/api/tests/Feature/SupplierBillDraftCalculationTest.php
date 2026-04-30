<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierBillDraftCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'status' => 'active']);
    }

    private function makeSupplier(string $tenantId, string $name = 'Supplier A'): Supplier
    {
        return Supplier::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_cash_bill_has_zero_credit_premium_and_uses_cash_price(): void
    {
        $tenant = $this->makeTenant('T1');
        $supplier = $this->makeSupplier($tenant->id);

        $pgBefore = PostingGroup::count();
        $leBefore = LedgerEntry::count();

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/supplier-bills', [
                'supplier_id' => $supplier->id,
                'bill_date' => '2026-04-01',
                'payment_terms' => 'CASH',
                'currency_code' => 'GBP',
                'lines' => [
                    [
                        'description' => 'Fertilizer',
                        'qty' => 10,
                        'cash_unit_price' => 5,
                        'credit_unit_price' => 6,
                    ],
                ],
            ]);

        $res->assertStatus(201);
        $body = $res->json();

        $this->assertEquals(SupplierBill::STATUS_DRAFT, $body['status']);
        $this->assertEquals('50.00', $body['subtotal_cash_amount']);
        $this->assertEquals('0.00', $body['credit_premium_total']);
        $this->assertEquals('50.00', $body['grand_total']);

        $this->assertCount(1, $body['lines']);
        $this->assertEquals('50.00', $body['lines'][0]['base_cash_amount']);
        $this->assertEquals('5.000000', $body['lines'][0]['selected_unit_price']);
        $this->assertEquals('0.00', $body['lines'][0]['credit_premium_amount']);
        $this->assertEquals('50.00', $body['lines'][0]['line_total']);

        $this->assertEquals($pgBefore, PostingGroup::count(), 'Draft bill must not create posting groups');
        $this->assertEquals($leBefore, LedgerEntry::count(), 'Draft bill must not create ledger entries');
    }

    public function test_credit_bill_calculates_credit_premium_and_uses_credit_price(): void
    {
        $tenant = $this->makeTenant('T2');
        $supplier = $this->makeSupplier($tenant->id, 'Supplier B');

        $pgBefore = PostingGroup::count();
        $leBefore = LedgerEntry::count();

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/supplier-bills', [
                'supplier_id' => $supplier->id,
                'bill_date' => '2026-04-02',
                'payment_terms' => 'CREDIT',
                'currency_code' => 'GBP',
                'lines' => [
                    [
                        'description' => 'Seeds',
                        'qty' => 10,
                        'cash_unit_price' => 5,
                        'credit_unit_price' => 6,
                    ],
                ],
            ]);

        $res->assertStatus(201);
        $body = $res->json();

        $this->assertEquals('50.00', $body['subtotal_cash_amount']);
        $this->assertEquals('10.00', $body['credit_premium_total']);
        $this->assertEquals('60.00', $body['grand_total']);

        $this->assertCount(1, $body['lines']);
        $this->assertEquals('50.00', $body['lines'][0]['base_cash_amount']);
        $this->assertEquals('6.000000', $body['lines'][0]['selected_unit_price']);
        $this->assertEquals('10.00', $body['lines'][0]['credit_premium_amount']);
        $this->assertEquals('60.00', $body['lines'][0]['line_total']);

        $this->assertEquals($pgBefore, PostingGroup::count(), 'Draft bill must not create posting groups');
        $this->assertEquals($leBefore, LedgerEntry::count(), 'Draft bill must not create ledger entries');
    }
}

