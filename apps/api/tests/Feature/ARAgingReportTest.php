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

class ARAgingReportTest extends TestCase
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
     * Create tenant, two customers, project, crop cycle. Post sales with due dates spanning buckets.
     * as_of = 2024-07-01: CURRENT (due 2024-07-01), 1_30 (due 2024-06-01), 31_60 (due 2024-05-01), 61_90 (due 2024-04-15), 90_plus (due 2024-04-01).
     */
    private function createTenantWithAgingSales(): array
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

        $partyA = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
            'party_types' => ['BUYER'],
        ]);
        $partyB = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer B',
            'party_types' => ['BUYER'],
        ]);

        $projectA = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $partyA->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project A',
            'status' => 'ACTIVE',
        ]);
        $projectB = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $partyB->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project B',
            'status' => 'ACTIVE',
        ]);

        // Sales for Customer A: current (100), 1_30 (200), 31_60 (300)
        $saleCurrent = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $partyA->id,
            'project_id' => $projectA->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 100.00,
            'posting_date' => '2024-06-01',
            'sale_date' => '2024-06-01',
            'due_date' => '2024-07-01',
            'status' => 'DRAFT',
        ]);
        $sale1_30 = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $partyA->id,
            'project_id' => $projectA->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 200.00,
            'posting_date' => '2024-05-15',
            'sale_date' => '2024-05-15',
            'due_date' => '2024-06-01',
            'status' => 'DRAFT',
        ]);
        // 31_60 bucket: as_of 2024-07-01, due 2024-05-15 = 47 days overdue
        $sale31_60 = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $partyA->id,
            'project_id' => $projectA->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 300.00,
            'posting_date' => '2024-04-20',
            'sale_date' => '2024-04-20',
            'due_date' => '2024-05-15',
            'status' => 'DRAFT',
        ]);

        // Sale for Customer B: 90_plus (400)
        $sale90Plus = Sale::create([
            'tenant_id' => $tenant->id,
            'buyer_party_id' => $partyB->id,
            'project_id' => $projectB->id,
            'crop_cycle_id' => $cropCycle->id,
            'amount' => 400.00,
            'posting_date' => '2024-03-01',
            'sale_date' => '2024-03-01',
            'due_date' => '2024-04-01',
            'status' => 'DRAFT',
        ]);

        foreach ([$saleCurrent, $sale1_30, $sale31_60, $sale90Plus] as $s) {
            $this->withHeader('X-Tenant-Id', $tenant->id)
                ->withHeader('X-User-Role', 'accountant')
                ->postJson("/api/sales/{$s->id}/post", [
                    'posting_date' => $s->posting_date->format('Y-m-d'),
                    'idempotency_key' => 'ar-aging-' . $s->id,
                ])
                ->assertStatus(201);
        }

        $saleCurrent->refresh();
        $sale1_30->refresh();
        $sale31_60->refresh();
        $sale90Plus->refresh();

        return compact('tenant', 'partyA', 'partyB', 'projectA', 'projectB', 'cropCycle', 'saleCurrent', 'sale1_30', 'sale31_60', 'sale90Plus');
    }

    public function test_ar_aging_returns_buckets_per_customer_and_grand_totals(): void
    {
        $data = $this->createTenantWithAgingSales();
        $tenant = $data['tenant'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame('2024-07-01', $body['as_of']);
        $this->assertArrayHasKey('customers', $body);
        $this->assertArrayHasKey('grand_totals', $body);

        $gt = $body['grand_totals'];
        $this->assertSame('100.00', $gt['current']);
        $this->assertSame('200.00', $gt['1_30']);
        $this->assertSame('300.00', $gt['31_60']);
        $this->assertSame('0.00', $gt['61_90']);
        $this->assertSame('400.00', $gt['90_plus']);
        $this->assertSame('1000.00', $gt['total']);

        $customers = $body['customers'];
        $this->assertCount(2, $customers);

        $custA = collect($customers)->firstWhere('party_id', $data['partyA']->id);
        $this->assertNotNull($custA);
        $this->assertSame('Customer A', $custA['party_name']);
        $this->assertSame('100.00', $custA['totals']['current']);
        $this->assertSame('200.00', $custA['totals']['1_30']);
        $this->assertSame('300.00', $custA['totals']['31_60']);
        $this->assertSame('600.00', $custA['totals']['total']);
        $this->assertCount(3, $custA['invoices']);

        $custB = collect($customers)->firstWhere('party_id', $data['partyB']->id);
        $this->assertNotNull($custB);
        $this->assertSame('Customer B', $custB['party_name']);
        $this->assertSame('400.00', $custB['totals']['90_plus']);
        $this->assertSame('400.00', $custB['totals']['total']);
        $this->assertCount(1, $custB['invoices']);
    }

    public function test_apply_payment_reduces_open_balance_and_bucket_totals(): void
    {
        $data = $this->createTenantWithAgingSales();
        $tenant = $data['tenant'];
        $sale1_30 = $data['sale1_30'];
        $partyA = $data['partyA'];
        $cropCycle = $data['cropCycle'];

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $partyA->id,
            'direction' => 'IN',
            'amount' => 150.00,
            'payment_date' => '2024-07-05',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'ar-aging-pmt-1',
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
                'allocation_date' => '2024-07-05',
                'allocations' => [
                    ['sale_id' => $sale1_30->id, 'amount' => '150.00'],
                ],
            ])
            ->assertStatus(200);

        // as_of must be on or after allocation_date so the applied amount is included. As of 2024-07-10, sale due 2024-07-01 is 9 days overdue (1_30).
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-10');

        $response->assertStatus(200);
        $body = $response->json();
        $gt = $body['grand_totals'];
        $this->assertSame('0.00', $gt['current']);
        $this->assertSame('100.00', $gt['1_30'], 'sale due 2024-07-01 is 9 days overdue');
        $this->assertSame('350.00', $gt['31_60'], '50 (partially paid) + 300');
        $this->assertSame('400.00', $gt['90_plus']);
        $this->assertSame('850.00', $gt['total']);

        $custA = collect($body['customers'])->firstWhere('party_id', $partyA->id);
        $invoice1_30 = collect($custA['invoices'])->firstWhere('sale_id', $sale1_30->id);
        $this->assertSame('200.00', $invoice1_30['amount']);
        $this->assertSame('150.00', $invoice1_30['applied']);
        $this->assertSame('50.00', $invoice1_30['open_balance']);
    }

    public function test_unapply_restores_open_balance(): void
    {
        $data = $this->createTenantWithAgingSales();
        $tenant = $data['tenant'];
        $sale1_30 = $data['sale1_30'];
        $partyA = $data['partyA'];
        $cropCycle = $data['cropCycle'];

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $partyA->id,
            'direction' => 'IN',
            'amount' => 150.00,
            'payment_date' => '2024-07-05',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-07-05',
                'idempotency_key' => 'ar-aging-pmt-2',
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
                'allocation_date' => '2024-07-05',
                'allocations' => [['sale_id' => $sale1_30->id, 'amount' => '150.00']],
            ])
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');

        $response->assertStatus(200);
        $gt = $response->json('grand_totals');
        $this->assertSame('200.00', $gt['1_30']);
        $this->assertSame('1000.00', $gt['total']);
    }

    public function test_reversed_sale_excluded_from_aging(): void
    {
        $data = $this->createTenantWithAgingSales();
        $tenant = $data['tenant'];
        $sale31_60 = $data['sale31_60'];
        $cropCycle = $data['cropCycle'];

        $reversalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'REVERSAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-07-02',
            'reversal_of_posting_group_id' => $sale31_60->posting_group_id,
        ]);
        $sale31_60->update([
            'status' => 'REVERSED',
            'reversal_posting_group_id' => $reversalPg->id,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging?as_of=2024-07-01');

        $response->assertStatus(200);
        $body = $response->json();
        $gt = $body['grand_totals'];
        $this->assertSame('100.00', $gt['current']);
        $this->assertSame('200.00', $gt['1_30']);
        $this->assertSame('0.00', $gt['31_60'], 'Reversed sale excluded');
        $this->assertSame('400.00', $gt['90_plus']);
        $this->assertSame('700.00', $gt['total']);

        $custA = collect($body['customers'])->firstWhere('party_id', $data['partyA']->id);
        $saleIds = array_column($custA['invoices'], 'sale_id');
        $this->assertNotContains($sale31_60->id, $saleIds);
    }

    public function test_ar_aging_defaults_as_of_to_today(): void
    {
        $data = $this->createTenantWithAgingSales();
        $tenant = $data['tenant'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/ar/aging');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertNotEmpty($body['as_of']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $body['as_of']);
    }
}
