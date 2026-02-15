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

class ARStatementTest extends TestCase
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
     * AR statement includes posted Sale (AR increases) and posted Payment IN (AR decreases), with running balance and posting_group_id.
     */
    public function test_ar_statement_includes_sale_and_payment_in_with_running_balance(): void
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
            'amount' => 1500.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'ar-stmt-sale-1',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 400.00,
            'payment_date' => '2024-06-20',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-20',
                'idempotency_key' => 'ar-stmt-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party->id}/ar-statement?from=2024-06-01&to=2024-06-30");

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('party', $data);
        $this->assertEquals($party->id, $data['party']['id']);
        $this->assertArrayHasKey('period', $data);
        $this->assertEquals('2024-06-01', $data['period']['from']);
        $this->assertEquals('2024-06-30', $data['period']['to']);
        $this->assertArrayHasKey('lines', $data);
        $this->assertCount(2, $data['lines']);

        $saleLine = collect($data['lines'])->firstWhere('source_type', 'SALE');
        $this->assertNotNull($saleLine);
        $this->assertEquals('1500.00', $saleLine['credit']);
        $this->assertEquals('0.00', $saleLine['debit']);
        $this->assertEquals('1500.00', $saleLine['running_balance']);
        $this->assertNotNull($saleLine['posting_group_id'], 'Sale line must reference posting_group_id');

        $paymentLine = collect($data['lines'])->firstWhere('source_type', 'PAYMENT');
        $this->assertNotNull($paymentLine);
        $this->assertEquals('400.00', $paymentLine['debit']);
        $this->assertEquals('0.00', $paymentLine['credit']);
        $this->assertEquals('1100.00', $paymentLine['running_balance']);
        $this->assertNotNull($paymentLine['posting_group_id'], 'Payment IN line must reference posting_group_id');

        $this->assertArrayHasKey('totals', $data);
        $this->assertEquals('400.00', $data['totals']['debit_total']);
        $this->assertEquals('1500.00', $data['totals']['credit_total']);
        $this->assertEquals('1100.00', $data['totals']['closing_balance']);
    }

    /**
     * Reversed sale is excluded from AR statement lines.
     * We simulate a reversed sale by marking it in DB (reversal_posting_group_id set) so we don't depend on full reverse API (which may require inventory).
     */
    public function test_ar_statement_excludes_reversed_sale(): void
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
            'amount' => 500.00,
            'posting_date' => '2024-06-15',
            'sale_date' => '2024-06-15',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'ar-rev-sale-1',
            ])
            ->assertStatus(201);

        $responseBefore = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party->id}/ar-statement?from=2024-06-01&to=2024-06-30");
        $responseBefore->assertStatus(200);
        $this->assertCount(1, $responseBefore->json('lines'));

        // Simulate reversal: mark sale as reversed (same semantics as reverse API)
        $sale->refresh();
        $reversalPg = \App\Models\PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'source_type' => 'REVERSAL',
            'source_id' => (string) \Illuminate\Support\Str::uuid(),
            'posting_date' => '2024-06-16',
            'reversal_of_posting_group_id' => $sale->posting_group_id,
        ]);
        $sale->update([
            'status' => 'REVERSED',
            'reversal_posting_group_id' => $reversalPg->id,
        ]);

        $responseAfter = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party->id}/ar-statement?from=2024-06-01&to=2024-06-30");
        $responseAfter->assertStatus(200);
        $data = $responseAfter->json();
        $this->assertCount(0, $data['lines'], 'Reversed sale must be excluded from AR statement');
        $this->assertEquals('0.00', $data['totals']['closing_balance']);
    }

    /**
     * Reversed payment IN is excluded from AR statement; Sale line remains.
     */
    public function test_ar_statement_excludes_reversed_payment_in(): void
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
            'amount' => 800.00,
            'posting_date' => '2024-06-10',
            'sale_date' => '2024-06-10',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/sales/{$sale->id}/post", [
                'posting_date' => '2024-06-10',
                'idempotency_key' => 'ar-rev-pmt-sale',
            ])
            ->assertStatus(201);

        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'direction' => 'IN',
            'amount' => 200.00,
            'payment_date' => '2024-06-18',
            'method' => 'CASH',
            'status' => 'DRAFT',
        ]);
        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/post", [
                'posting_date' => '2024-06-18',
                'idempotency_key' => 'ar-rev-pmt-1',
                'crop_cycle_id' => $cropCycle->id,
            ])
            ->assertStatus(201);

        $responseBefore = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party->id}/ar-statement?from=2024-06-01&to=2024-06-30");
        $responseBefore->assertStatus(200);
        $this->assertCount(2, $responseBefore->json('lines'));

        $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/payments/{$payment->id}/reverse", [
                'posting_date' => '2024-06-19',
                'reason' => 'Test exclusion',
            ])
            ->assertStatus(201);

        $responseAfter = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/parties/{$party->id}/ar-statement?from=2024-06-01&to=2024-06-30");
        $responseAfter->assertStatus(200);
        $data = $responseAfter->json();
        $this->assertCount(1, $data['lines'], 'Only Sale line must remain after Payment IN is reversed');
        $this->assertEquals('SALE', $data['lines'][0]['source_type']);
        $this->assertEquals('800.00', $data['totals']['closing_balance']);
    }
}
