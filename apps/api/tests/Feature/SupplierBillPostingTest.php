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
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierBillPostingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantWithProject(string $tenantName, string $cycleStatus = 'OPEN'): array
    {
        $tenant = Tenant::create(['name' => $tenantName, 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => $cycleStatus,
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

        return compact('tenant', 'cycle', 'project', 'supplier');
    }

    private function createDraftBill(string $tenantId, string $supplierId, string $paymentTerms, array $lines): SupplierBill
    {
        $bill = SupplierBill::create([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplierId,
            'bill_date' => '2026-04-01',
            'currency_code' => 'GBP',
            'payment_terms' => $paymentTerms,
            'status' => SupplierBill::STATUS_DRAFT,
            'subtotal_cash_amount' => $paymentTerms === 'CREDIT' ? '50.00' : '50.00',
            'credit_premium_total' => $paymentTerms === 'CREDIT' ? '10.00' : '0.00',
            'grand_total' => $paymentTerms === 'CREDIT' ? '60.00' : '50.00',
        ]);

        $n = 1;
        foreach ($lines as $l) {
            SupplierBillLine::create([
                'tenant_id' => $tenantId,
                'supplier_bill_id' => $bill->id,
                'line_no' => $n++,
                'description' => $l['description'] ?? null,
                'project_id' => $l['project_id'] ?? null,
                'crop_cycle_id' => $l['crop_cycle_id'] ?? null,
                'cost_category' => $l['cost_category'] ?? 'OTHER',
                'qty' => $l['qty'],
                'cash_unit_price' => $l['cash_unit_price'],
                'credit_unit_price' => $l['credit_unit_price'] ?? null,
                'base_cash_amount' => $l['base_cash_amount'],
                'selected_unit_price' => $l['selected_unit_price'],
                'credit_premium_amount' => $l['credit_premium_amount'],
                'line_total' => $l['line_total'],
            ]);
        }

        return $bill->fresh(['lines']);
    }

    public function test_cash_bill_post_creates_single_posting_group_allocations_and_balanced_ledger(): void
    {
        $data = $this->seedTenantWithProject('T-post-cash');
        $tenant = $data['tenant'];
        $project = $data['project'];
        $cycle = $data['cycle'];
        $supplier = $data['supplier'];

        // Ensure required premium expense account exists even if migrations aren't run in test order.
        \DB::table('accounts')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'code' => 'CREDIT_PURCHASE_PREMIUM_EXPENSE',
            'name' => 'Credit Purchase Premium Expense',
            'type' => 'expense',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill = $this->createDraftBill($tenant->id, $supplier->id, 'CASH', [
            [
                'description' => 'Item',
                'project_id' => $project->id,
                'crop_cycle_id' => $cycle->id,
                'qty' => 10,
                'cash_unit_price' => 5,
                'credit_unit_price' => 6,
                'base_cash_amount' => '50.00',
                'selected_unit_price' => '5.000000',
                'credit_premium_amount' => '0.00',
                'line_total' => '50.00',
            ],
        ]);

        $res = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-bills/{$bill->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sb-post-cash-1',
            ]);
        $res->assertStatus(201);

        $bill->refresh();
        $this->assertEquals(SupplierBill::STATUS_POSTED, $bill->status);
        $this->assertNotNull($bill->posting_group_id);

        $pg = PostingGroup::find($bill->posting_group_id);
        $this->assertNotNull($pg);
        $this->assertEquals('SUPPLIER_BILL', $pg->source_type);
        $this->assertEquals($bill->id, $pg->source_id);

        $allocBase = \App\Models\AllocationRow::where('posting_group_id', $pg->id)
            ->where('allocation_type', 'SUPPLIER_BILL_BASE')
            ->sum('amount');
        $allocPrem = \App\Models\AllocationRow::where('posting_group_id', $pg->id)
            ->where('allocation_type', 'SUPPLIER_BILL_CREDIT_PREMIUM')
            ->sum('amount');
        $this->assertEquals(50, (float) $allocBase);
        $this->assertEquals(0, (float) $allocPrem);

        $ap = Account::where('tenant_id', $tenant->id)->where('code', 'AP')->first();
        $base = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();
        $this->assertNotNull($ap);
        $this->assertNotNull($base);

        $drBase = (float) LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $base->id)->sum('debit_amount');
        $crAp = (float) LedgerEntry::where('posting_group_id', $pg->id)->where('account_id', $ap->id)->sum('credit_amount');
        $this->assertEquals(50, $drBase);
        $this->assertEquals(50, $crAp);

        $sumDr = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('debit_amount');
        $sumCr = (float) LedgerEntry::where('posting_group_id', $pg->id)->sum('credit_amount');
        $this->assertEqualsWithDelta($sumDr, $sumCr, 0.01);
    }

    public function test_credit_bill_post_books_premium_separately_and_is_idempotent(): void
    {
        $data = $this->seedTenantWithProject('T-post-credit');
        $tenant = $data['tenant'];
        $project = $data['project'];
        $cycle = $data['cycle'];
        $supplier = $data['supplier'];

        \DB::table('accounts')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'code' => 'CREDIT_PURCHASE_PREMIUM_EXPENSE',
            'name' => 'Credit Purchase Premium Expense',
            'type' => 'expense',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill = $this->createDraftBill($tenant->id, $supplier->id, 'CREDIT', [
            [
                'description' => 'Item',
                'project_id' => $project->id,
                'crop_cycle_id' => $cycle->id,
                'qty' => 10,
                'cash_unit_price' => 5,
                'credit_unit_price' => 6,
                'base_cash_amount' => '50.00',
                'selected_unit_price' => '6.000000',
                'credit_premium_amount' => '10.00',
                'line_total' => '60.00',
            ],
        ]);

        $res1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-bills/{$bill->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sb-post-credit-1',
            ]);
        $res1->assertStatus(201);

        $bill->refresh();
        $pgId = $bill->posting_group_id;
        $this->assertNotNull($pgId);

        // Re-post: should return existing and not duplicate.
        $ledgerCountBefore = LedgerEntry::where('posting_group_id', $pgId)->count();
        $allocCountBefore = \App\Models\AllocationRow::where('posting_group_id', $pgId)->count();

        $res2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-bills/{$bill->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sb-post-credit-1',
            ]);
        $res2->assertStatus(201);

        $this->assertEquals($ledgerCountBefore, LedgerEntry::where('posting_group_id', $pgId)->count());
        $this->assertEquals($allocCountBefore, \App\Models\AllocationRow::where('posting_group_id', $pgId)->count());

        $ap = Account::where('tenant_id', $tenant->id)->where('code', 'AP')->first();
        $base = Account::where('tenant_id', $tenant->id)->where('code', 'INPUTS_EXPENSE')->first();
        $prem = Account::where('tenant_id', $tenant->id)->where('code', 'CREDIT_PURCHASE_PREMIUM_EXPENSE')->first();
        $this->assertNotNull($ap);
        $this->assertNotNull($base);
        $this->assertNotNull($prem);

        $drBase = (float) LedgerEntry::where('posting_group_id', $pgId)->where('account_id', $base->id)->sum('debit_amount');
        $drPrem = (float) LedgerEntry::where('posting_group_id', $pgId)->where('account_id', $prem->id)->sum('debit_amount');
        $crAp = (float) LedgerEntry::where('posting_group_id', $pgId)->where('account_id', $ap->id)->sum('credit_amount');
        $this->assertEquals(50, $drBase);
        $this->assertEquals(10, $drPrem);
        $this->assertEquals(60, $crAp);
    }

    public function test_closed_crop_cycle_blocks_posting(): void
    {
        $data = $this->seedTenantWithProject('T-closed', 'CLOSED');
        $tenant = $data['tenant'];
        $project = $data['project'];
        $cycle = $data['cycle'];
        $supplier = $data['supplier'];

        \DB::table('accounts')->insertOrIgnore([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $tenant->id,
            'code' => 'CREDIT_PURCHASE_PREMIUM_EXPENSE',
            'name' => 'Credit Purchase Premium Expense',
            'type' => 'expense',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bill = $this->createDraftBill($tenant->id, $supplier->id, 'CASH', [
            [
                'description' => 'Item',
                'project_id' => $project->id,
                'crop_cycle_id' => $cycle->id,
                'qty' => 1,
                'cash_unit_price' => 1,
                'base_cash_amount' => '1.00',
                'selected_unit_price' => '1.000000',
                'credit_premium_amount' => '0.00',
                'line_total' => '1.00',
            ],
        ]);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/supplier-bills/{$bill->id}/post", [
                'posting_date' => '2026-04-10',
                'idempotency_key' => 'sb-post-closed-1',
            ])
            ->assertStatus(422);
    }
}

