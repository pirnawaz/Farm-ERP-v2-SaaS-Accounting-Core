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
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ARControlReconciliationReportTest extends TestCase
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
     * Invoice + cash payment + allocation same day => open_invoices_total=0, gl_ar_total=0, delta=0
     */
    public function test_invoice_payment_allocation_same_day_reconciles_to_zero(): void
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
            'amount' => 300.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ar-recon-inv-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 300.00,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ar-recon-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        // Payment IN auto-applies FIFO on post, so no need to call apply-sales

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ar-control-reconciliation?as_of=2024-06-01');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('2024-06-01', $body['as_of']);
        $this->assertEquals(0, $body['subledger_open_invoices_total']);
        $this->assertEquals(0, $body['gl_ar_total']);
        $this->assertEquals(0, $body['delta']);
        $this->assertCount(0, $body['open_invoices']);
    }

    /**
     * Allocation cutoff: post invoice 100 day1, payment 100 day1, apply allocation day10.
     * Report as_of day5: subledger_open_invoices_total=100, gl_ar_total=0, delta=100, unapplied_payments_total explains it.
     */
    public function test_allocation_cutoff_as_of_before_apply_shows_delta_explained_by_unapplied(): void
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
                'idempotency_key' => 'ar-recon-cutoff-inv',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 100.00,
            'payment_date' => '2024-06-01',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ar-recon-cutoff-pmt',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        // Post auto-applies FIFO (allocation_date = posting_date = day1). Unapply then re-apply with allocation_date day10.
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-sales", [
                'mode' => 'MANUAL',
                'allocation_date' => '2024-06-10',
                'allocations' => [['sale_id' => $sale->id, 'amount' => '100.00']],
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ar-control-reconciliation?as_of=2024-06-05');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('2024-06-05', $body['as_of']);
        $this->assertEquals(100, $body['subledger_open_invoices_total'], 'As of day5 allocation not yet applied so full invoice open');
        $this->assertEquals(0, $body['gl_ar_total'], 'GL AR: invoice DR 100, payment CR 100');
        $this->assertEquals(100, $body['delta']);
        $this->assertEquals(100, $body['unapplied_payments_total'], 'Delta explained by unapplied payment');
        $this->assertEquals(100, $body['explained_delta']);
        $this->assertCount(1, $body['open_invoices']);
        $this->assertSame('100.00', $body['open_invoices'][0]['open_balance_as_of']);
    }

    /**
     * Credit note integration: post invoice 200, post credit note 50, apply credit note allocation.
     * Report after allocation: subledger=150, gl=150, delta=0.
     */
    public function test_credit_note_integration_reconciles_after_apply(): void
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
                'idempotency_key' => 'ar-recon-cn-inv',
            ])
            ->assertStatus(201);
        $invoice->refresh();

        $creditNote = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $party->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 50.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'sale_kind' => Sale::SALE_KIND_CREDIT_NOTE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'ar-recon-cn-1',
            ])
            ->assertStatus(201);
        $creditNote->refresh();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$creditNote->id}/apply-to-invoices", [
                'allocation_date' => '2024-06-10',
                'allocations' => [['sale_id' => $invoice->id, 'amount' => '50.00']],
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ar-control-reconciliation?as_of=2024-06-15');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals(150, $body['subledger_open_invoices_total'], 'Invoice 200 - allocated 50 = 150');
        $this->assertEquals(150, $body['gl_ar_total'], 'GL: invoice 200 - credit note 50 = 150');
        $this->assertEquals(0, $body['delta']);
        $this->assertCount(1, $body['open_invoices']);
        $this->assertSame('150.00', $body['open_invoices'][0]['open_balance_as_of']);
    }

    /**
     * Reversed invoice excluded: post invoice then reverse it; report does not show it and totals align.
     */
    public function test_reversed_invoice_excluded_totals_align(): void
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
            'amount' => 75.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'sale_kind' => Sale::SALE_KIND_INVOICE,
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-01',
                'idempotency_key' => 'ar-recon-rev-inv',
            ])
            ->assertStatus(201);
        $sale->refresh();

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-05',
                'reason' => 'Test reversal',
            ])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/ar-control-reconciliation?as_of=2024-06-10');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals(0, $body['subledger_open_invoices_total']);
        $this->assertEquals(0, $body['gl_ar_total'], 'Original DR 75 + reversal CR 75 = 0');
        $this->assertEquals(0, $body['delta']);
        $this->assertCount(0, $body['open_invoices']);
    }
}
