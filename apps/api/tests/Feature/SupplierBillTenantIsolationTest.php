<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierBillTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cannot_access_other_tenant_supplier_or_bills(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'status' => 'active']);

        $supplierB = Supplier::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Supplier B',
            'status' => 'ACTIVE',
        ]);

        // Tenant A should not be able to see Tenant B supplier
        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/suppliers/' . $supplierB->id)
            ->assertStatus(404);

        // Tenant A should not be able to create a bill referencing Tenant B supplier
        $this->withHeader('X-Tenant-Id', $tenantA->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/supplier-bills', [
                'supplier_id' => $supplierB->id,
                'bill_date' => '2026-04-03',
                'payment_terms' => 'CASH',
                'currency_code' => 'GBP',
                'lines' => [
                    [
                        'description' => 'Line',
                        'qty' => 1,
                        'cash_unit_price' => 1,
                    ],
                ],
            ])
            ->assertStatus(404);
    }
}

