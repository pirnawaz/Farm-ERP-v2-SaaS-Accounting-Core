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
use App\Models\SalePaymentAllocation;
use App\Models\PostingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class PaymentApplySalesTest extends TestCase
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
     * Create tenant, party, project, crop cycle, and post one sale + one payment IN. Returns [tenant, party, project, sale, payment].
     */
    private function createPostedSaleAndPayment(): array
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['ar_sales', 'treasury_payments']);
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
            'amount' => 1000.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'apply-test-sale-1',
            ])
            ->assertStatus(201);
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 600.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'apply-test-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);
        $payment->refresh();
        $sale->refresh();
        return compact('tenant', 'party', 'project', 'sale', 'payment');
    }

    public function test_preview_returns_fifo_suggestions_for_open_sales(): void
    {
        $data = $this->createPostedSaleAndPayment();
        $tenant = $data['tenant'];
        $payment = $data['payment'];
        // Post auto-allocates FIFO; unapply so we have unapplied amount to preview
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/payments/{$payment->id}/apply-sales/preview?mode=FIFO");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertArrayHasKey('payment_summary', $body);
        $this->assertEquals($payment->id, $body['payment_summary']['id']);
        $this->assertEquals('600.00', $body['payment_summary']['amount']);
        $this->assertEquals('600.00', $body['payment_summary']['unapplied_amount']);
        $this->assertArrayHasKey('open_sales', $body);
        $this->assertCount(1, $body['open_sales']);
        $this->assertEquals($data['sale']->id, $body['open_sales'][0]['sale_id']);
        $this->assertArrayHasKey('suggested_allocations', $body);
        $this->assertCount(1, $body['suggested_allocations']);
        $this->assertEquals($data['sale']->id, $body['suggested_allocations'][0]['sale_id']);
        $this->assertEquals('600.00', $body['suggested_allocations'][0]['amount']);
    }

    public function test_apply_fifo_creates_active_allocations_and_reduces_unapplied(): void
    {
        $data = $this->createPostedSaleAndPayment();
        $tenant = $data['tenant'];
        $payment = $data['payment'];
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-sales", [
                'mode' => 'FIFO',
                'allocation_date' => '2024-06-20',
            ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals($payment->id, $body['payment_id']);
        $this->assertEquals('600.00', $body['amount']);
        $this->assertEquals('0.00', $body['unapplied_amount']);
        $this->assertCount(1, $body['allocations']);
        $this->assertEquals($data['sale']->id, $body['allocations'][0]['sale_id']);
        $this->assertEquals('600.00', $body['allocations'][0]['amount']);

        $active = SalePaymentAllocation::where('payment_id', $payment->id)->where('status', 'ACTIVE')->get();
        $this->assertCount(1, $active);
    }

    public function test_apply_manual_rejects_over_allocation(): void
    {
        $data = $this->createPostedSaleAndPayment();
        $tenant = $data['tenant'];
        $payment = $data['payment'];
        $sale = $data['sale'];
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        // Request more than unapplied (600 available, request 700)
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-sales", [
                'mode' => 'MANUAL',
                'allocations' => [
                    ['sale_id' => $sale->id, 'amount' => '700.00'],
                ],
            ]);

        $response->assertStatus(422);
        $error = $response->json('error') ?? '';
        $this->assertTrue(
            str_contains($error, 'exceeds unapplied') || str_contains($error, 'Total allocation amount'),
            'Error should mention over-allocation: ' . $error
        );
    }

    public function test_unapply_voids_allocations_and_restores_unapplied(): void
    {
        $data = $this->createPostedSaleAndPayment();
        $tenant = $data['tenant'];
        $payment = $data['payment'];
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/apply-sales", ['mode' => 'FIFO'])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", []);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals('600.00', $body['unapplied_amount']);
        $this->assertCount(0, $body['allocations']);

        $voided = SalePaymentAllocation::where('payment_id', $payment->id)->where('status', 'VOID')->get();
        $this->assertCount(2, $voided, 'Both allocations (from post and from apply) must be voided');
        $this->assertNotNull($voided[0]->voided_at);
    }

    public function test_cannot_apply_to_reversed_sale_or_reversed_payment(): void
    {
        $data = $this->createPostedSaleAndPayment();
        $tenant = $data['tenant'];
        $payment = $data['payment'];
        $sale = $data['sale'];
        $cropCycleId = $data['project']->crop_cycle_id;

        // Simulate reversed payment: set reversal_posting_group_id to a valid FK
        $dummyReversalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'REVERSAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-21',
            'reversal_of_posting_group_id' => $payment->posting_group_id,
        ]);
        $payment->update(['reversal_posting_group_id' => $dummyReversalPg->id]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/payments/{$payment->id}/apply-sales/preview?mode=FIFO");
        $response->assertStatus(404);

        // Restore payment and reverse the sale instead
        $payment->update(['reversal_posting_group_id' => null]);
        $saleReversalPg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycleId,
            'source_type' => 'REVERSAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-16',
            'reversal_of_posting_group_id' => $sale->posting_group_id,
        ]);
        $sale->update(['status' => 'REVERSED', 'reversal_posting_group_id' => $saleReversalPg->id]);

        $response2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/payments/{$payment->id}/apply-sales/preview?mode=FIFO");
        $response2->assertStatus(200);
        $body = $response2->json();
        $this->assertCount(0, $body['open_sales']);
        $this->assertCount(0, $body['suggested_allocations']);
    }
}
