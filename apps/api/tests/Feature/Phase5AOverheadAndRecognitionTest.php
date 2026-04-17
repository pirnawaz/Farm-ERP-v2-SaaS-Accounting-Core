<?php

namespace Tests\Feature;

use App\Domains\Reporting\ProjectPLQueryService;
use App\Models\CostCenter;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\ProjectProfitabilityService;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5AOverheadAndRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_overhead_allocation_post_creates_posting_and_project_pl_sees_cost(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5A', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'HQ',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $p1 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Wheat',
            'status' => 'ACTIVE',
        ]);
        $p2 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Cotton',
            'status' => 'ACTIVE',
        ]);

        $bill = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'OH-300',
            'invoice_date' => '2024-03-01',
            'total_amount' => 300.00,
            'lines' => [['description' => 'Rent', 'line_total' => 300.00]],
        ])->assertCreated()->json();

        $pg = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$bill['id'].'/post', [
            'posting_date' => '2024-03-10',
            'idempotency_key' => 'p5a-bill',
        ])->assertCreated()->json();

        $sourcePgId = $pg['id'];

        $draft = $this->withHeaders($this->headers($tenant))->postJson('/api/overhead-allocations', [
            'cost_center_id' => $cc->id,
            'source_posting_group_id' => $sourcePgId,
            'allocation_date' => '2024-03-15',
            'method' => 'EQUAL_SHARE',
            'total_amount' => 200,
            'lines' => [
                ['project_id' => $p1->id],
                ['project_id' => $p2->id],
            ],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/overhead-allocations/'.$draft['id'].'/post', [
            'idempotency_key' => 'p5a-alloc',
        ])->assertCreated();

        $pl1 = app(ProjectPLQueryService::class)->getProjectPlRows(
            $tenant->id,
            '2024-03-01',
            '2024-03-31',
            $p1->id,
            null
        );
        $this->assertCount(1, $pl1);
        $this->assertEqualsWithDelta(100.00, (float) $pl1[0]['expenses'], 0.05);

        $profit = app(ProjectProfitabilityService::class)->getProjectProfitability($p1->id, $tenant->id, [
            'from' => '2024-03-01',
            'to' => '2024-03-31',
        ]);
        $this->assertEqualsWithDelta(100.00, $profit['totals']['cost'], 0.05);

        $oh = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/overheads?from=2024-03-01&to=2024-03-31'
        )->assertOk()->json();

        $this->assertArrayHasKey('allocation_summary', $oh);
        $this->assertEqualsWithDelta(200.0, (float) $oh['allocation_summary']['allocated_to_projects'], 0.05);

        $farmBefore = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from=2024-03-01&to=2024-03-31'
        )->assertOk()->json();
        $combined = (float) $farmBefore['combined']['net_farm_operating_result'];
        $projNet = (float) $farmBefore['projects']['totals']['net_profit'];
        $ohNet = (float) $farmBefore['overhead']['grand_totals']['net'];
        $this->assertEqualsWithDelta($projNet + $ohNet, $combined, 0.05);
    }

    public function test_over_allocation_rejected(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5A2', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'HQ',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $p1 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'A',
            'status' => 'ACTIVE',
        ]);

        $bill = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'reference_no' => 'OH-100',
            'invoice_date' => '2024-04-01',
            'total_amount' => 100.00,
            'lines' => [['description' => 'X', 'line_total' => 100.00]],
        ])->assertCreated()->json();

        $pg = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$bill['id'].'/post', [
            'posting_date' => '2024-04-05',
            'idempotency_key' => 'p5a2',
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/overhead-allocations', [
            'cost_center_id' => $cc->id,
            'source_posting_group_id' => $pg['id'],
            'allocation_date' => '2024-04-10',
            'method' => 'EQUAL_SHARE',
            'total_amount' => 150,
            'lines' => [['project_id' => $p1->id]],
        ])->assertStatus(422);
    }

    public function test_bill_prepaid_schedule_deferral_and_recognition(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P5A3', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'HQ',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);

        $bill = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'reference_no' => 'INS',
            'invoice_date' => '2024-06-01',
            'total_amount' => 120.00,
            'lines' => [['description' => 'Insurance', 'line_total' => 120.00]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$bill['id'].'/post', [
            'posting_date' => '2024-06-01',
            'idempotency_key' => 'p5a3-post',
        ])->assertCreated();

        $sched = $this->withHeaders($this->headers($tenant))->postJson('/api/bill-recognition-schedules', [
            'supplier_invoice_id' => $bill['id'],
            'treatment' => 'PREPAID',
            'start_date' => '2024-06-01',
            'end_date' => '2024-08-31',
            'total_amount' => 120.00,
        ])->assertCreated()->json();

        $this->assertCount(3, $sched['lines']);

        $this->withHeaders($this->headers($tenant))->postJson('/api/bill-recognition-schedules/'.$sched['id'].'/post-deferral', [
            'posting_date' => '2024-06-02',
            'idempotency_key' => 'p5a3-def',
        ])->assertCreated();

        $schedFresh = $this->withHeaders($this->headers($tenant))->getJson('/api/bill-recognition-schedules/'.$sched['id'])
            ->assertOk()->json();
        $this->assertSame('DEFERRAL_POSTED', $schedFresh['schedule']['status']);
        $this->assertNotNull($schedFresh['schedule']['deferral_posting_group_id']);

        $lineId = $schedFresh['schedule']['lines'][0]['id'];
        $this->withHeaders($this->headers($tenant))->postJson('/api/bill-recognition-schedule-lines/'.$lineId.'/post-recognition', [
            'posting_date' => '2024-06-30',
            'idempotency_key' => 'p5a3-rec1',
        ])->assertCreated();

        $pg = PostingGroup::query()->where('tenant_id', $tenant->id)->where('source_type', 'BILL_RECOGNITION')->first();
        $this->assertNotNull($pg);
    }
}
