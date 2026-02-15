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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;
use Database\Seeders\ModulesSeeder;

class ReversalGuardsTest extends TestCase
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
     * Create posted sale + posted payment IN (post auto-applies, so ACTIVE allocation exists).
     * Returns [tenant, sale, payment].
     */
    private function createPostedSaleAndPaymentWithAllocation(): array
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
                'idempotency_key' => 'guard-sale-1',
            ])
            ->assertStatus(201);
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 500.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'guard-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);
        $payment->refresh();
        $sale->refresh();
        return compact('tenant', 'sale', 'payment');
    }

    public function test_payment_reversal_blocked_when_has_active_allocations(): void
    {
        $data = $this->createPostedSaleAndPaymentWithAllocation();
        $tenant = $data['tenant'];
        $payment = $data['payment'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-21',
                'reason' => 'Test',
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('Unapply sales allocations', $response->json('message') ?? '');
    }

    public function test_sale_reversal_blocked_when_has_active_allocations(): void
    {
        $data = $this->createPostedSaleAndPaymentWithAllocation();
        $tenant = $data['tenant'];
        $sale = $data['sale'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-21',
                'reason' => 'Test',
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('Unapply before reversing the sale', $response->json('error') ?? '');
    }

    public function test_unapply_then_payment_reversal_succeeds(): void
    {
        $data = $this->createPostedSaleAndPaymentWithAllocation();
        $tenant = $data['tenant'];
        $payment = $data['payment'];

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-21',
                'reason' => 'Test',
            ])
            ->assertStatus(409);

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-21',
                'reason' => 'Test',
            ]);

        $response->assertStatus(201);
    }

    public function test_unapply_then_sale_reversal_succeeds(): void
    {
        $data = $this->createPostedSaleAndPaymentWithAllocation();
        $tenant = $data['tenant'];
        $sale = $data['sale'];
        $payment = $data['payment'];

        $r = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-21',
                'reason' => 'Test',
            ]);
        $r->assertStatus(409);
        $this->assertStringContainsString('Unapply', $r->json('error') ?? '');

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/unapply-sales", [])
            ->assertStatus(200);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/reverse", [
                'reversal_date' => '2024-06-21',
                'reason' => 'Test',
            ]);

        $response->assertStatus(200);
        $sale->refresh();
        $this->assertEquals('REVERSED', $sale->status);
    }
}
