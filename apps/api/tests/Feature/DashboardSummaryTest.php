<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_returns_401_when_no_role(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/dashboard/summary');

        $response->assertStatus(401);
    }

    public function test_dashboard_summary_returns_400_when_no_tenant(): void
    {
        $response = $this->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/dashboard/summary');

        $response->assertStatus(400);
    }

    public function test_dashboard_summary_returns_200_for_authenticated_tenant_user(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'tenant_admin')
            ->getJson('/api/dashboard/summary');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('scope', $data);
        $this->assertArrayHasKey('farm', $data);
        $this->assertArrayHasKey('money', $data);
        $this->assertArrayHasKey('profit', $data);
        $this->assertArrayHasKey('governance', $data);
        $this->assertArrayHasKey('alerts', $data);
        $this->assertArrayHasKey('type', $data['scope']);
        $this->assertArrayHasKey('id', $data['scope']);
        $this->assertArrayHasKey('label', $data['scope']);
        $this->assertArrayHasKey('active_crop_cycles_count', $data['farm']);
        $this->assertArrayHasKey('open_projects_count', $data['farm']);
        $this->assertArrayHasKey('harvests_this_cycle_count', $data['farm']);
        $this->assertArrayHasKey('unposted_records_count', $data['farm']);
        $this->assertArrayHasKey('cash_balance', $data['money']);
        $this->assertArrayHasKey('bank_balance', $data['money']);
        $this->assertArrayHasKey('receivables_total', $data['money']);
        $this->assertArrayHasKey('advances_outstanding_total', $data['money']);
        $this->assertArrayHasKey('profit_this_cycle', $data['profit']);
        $this->assertArrayHasKey('profit_ytd', $data['profit']);
        $this->assertArrayHasKey('best_project', $data['profit']);
        $this->assertArrayHasKey('cost_per_acre', $data['profit']);
        $this->assertArrayHasKey('settlements_pending_count', $data['governance']);
        $this->assertArrayHasKey('cycles_closed_count', $data['governance']);
        $this->assertArrayHasKey('locks_warning', $data['governance']);
    }

    public function test_dashboard_summary_tenant_isolation(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        CropCycle::create([
            'tenant_id' => $tenant1->id,
            'name' => 'T1 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        CropCycle::create([
            'tenant_id' => $tenant2->id,
            'name' => 'T2 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/dashboard/summary');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('T1 Cycle', $data['scope']['label']);
        $this->assertEquals(1, $data['farm']['active_crop_cycles_count']);
    }
}
