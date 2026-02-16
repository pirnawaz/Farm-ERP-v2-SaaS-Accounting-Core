<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\PostingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class CustomerBalanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
        (new ModulesSeeder)->run();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        foreach ($keys as $key) {
            $module = Module::where('key', $key)->first();
            if ($module) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    /**
     * Invoice-only customer: open_invoices_total 100, unapplied_total 0, net_balance 100
     */
    public function test_invoice_only_customer_balance_shows_open_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $partyA = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer A',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $partyA->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project A',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $partyA->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-inv-only',
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-01');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('2024-06-01', $body['as_of']);
        $this->assertCount(1, $body['rows']);
        $this->assertEquals(100, $body['rows'][0]['open_invoices_total']);
        $this->assertEquals(0, $body['rows'][0]['unapplied_total']);
        $this->assertEquals(100, $body['rows'][0]['net_balance']);
        $this->assertEquals(100, $body['totals']['open_invoices_total']);
        $this->assertEquals(0, $body['totals']['unapplied_total']);
        $this->assertEquals(100, $body['totals']['net_balance']);
    }

    /**
     * Unapplied payment: open_invoices_total 100, unapplied_total 60, net_balance 40
     */
    public function test_unapplied_payment_reduces_net_balance_but_not_invoice_open(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'treasury_payments', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-unapplied-inv',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 60.00,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-unapplied-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-01');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(1, $body['rows']);
        $this->assertEquals(100, $body['rows'][0]['open_invoices_total']);
        $this->assertEquals(60, $body['rows'][0]['unapplied_total']);
        $this->assertEquals(40, $body['rows'][0]['net_balance']);
    }

    /**
     * Allocation cutoff: as_of day1 same as test 2; as_of day2 after apply => open 40, unapplied 0, net 40
     */
    public function test_applied_payment_moves_from_unapplied_to_open_invoice_reduction(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'treasury_payments', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-apply-inv',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 60.00,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-apply-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-sales", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-02',
                'allocations' => [['sale_id' => $sale->id, 'amount' => '60.00']],
            ])
            ->assertStatus(200);

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-01');
        $r1->assertStatus(200);
        $this->assertEquals(100, $r1->json('rows.0.open_invoices_total'), 'As of day1 allocation not yet counted');
        $this->assertEquals(60, $r1->json('rows.0.unapplied_total'));
        $this->assertEquals(40, $r1->json('rows.0.net_balance'));

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-02');
        $r2->assertStatus(200);
        $this->assertEquals(40, $r2->json('rows.0.open_invoices_total'));
        $this->assertEquals(0, $r2->json('rows.0.unapplied_total'));
        $this->assertEquals(40, $r2->json('rows.0.net_balance'));
    }

    /**
     * Credit note: before apply => open 200, unapplied 50, net 150; after apply => open 150, unapplied 0, net 150
     */
    public function test_credit_note_behaves_as_unapplied_credit_until_applied(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $invoice = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 200.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$invoice->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-cn-inv',
            ])
            ->assertStatus(201);
        $invoice->refresh();

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 50.00,
            'posting_date' => '2024-06-05',
            'sale_date' => '2024-06-05',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-05',
                'idempotency_key' => 'cb-cn-1',
            ])
            ->assertStatus(201);
        $creditNote->refresh();

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-10');
        $r1->assertStatus(200);
        $this->assertEquals(200, $r1->json('rows.0.open_invoices_total'));
        $this->assertEquals(50, $r1->json('rows.0.unapplied_total'));
        $this->assertEquals(150, $r1->json('rows.0.net_balance'));

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/apply-to-invoices", [
                'allocation_date' => '2024-06-10',
                'allocations' => [['sale_id' => $invoice->id, 'amount' => '50.00']],
            ])
            ->assertStatus(200);

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-15');
        $r2->assertStatus(200);
        $this->assertEquals(150, $r2->json('rows.0.open_invoices_total'));
        $this->assertEquals(0, $r2->json('rows.0.unapplied_total'));
        $this->assertEquals(150, $r2->json('rows.0.net_balance'));
    }

    /**
     * Reversed invoice or payment excluded from totals and drilldown
     */
    public function test_reversed_items_excluded(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'reports']);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Buyer',
            'party_types' => ['BUYER'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $sale = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 80.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'cb-rev-inv',
            ])
            ->assertStatus(201);
        $sale->refresh();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-05',
                'reason' => 'Test',
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balances?as_of=2024-06-10');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(0, $body['rows'], 'Reversed invoice customer should not appear');
        $this->assertEquals(0, $body['totals']['open_invoices_total']);
        $this->assertEquals(0, $body['totals']['unapplied_total']);
        $this->assertEquals(0, $body['totals']['net_balance']);

        $detail = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/customer-balance-detail?as_of=2024-06-10&buyer_party_id=' . $party->id);
        $detail->assertStatus(200);
        $this->assertCount(0, $detail->json('open_invoices'));
    }
}
