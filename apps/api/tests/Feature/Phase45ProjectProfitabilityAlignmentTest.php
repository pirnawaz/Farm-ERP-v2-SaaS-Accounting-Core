<?php

namespace Tests\Feature;

use App\Domains\Reporting\ProjectPLQueryService;
use App\Models\CostCenter;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\ProjectProfitabilityService;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.5 — Project profitability eligibility matches project-pl (allocation-backed); supplier invoices included.
 */
class Phase45ProjectProfitabilityAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_posted_project_scoped_supplier_invoice_in_profitability_and_matches_project_pl(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P45 T', 'status' => 'active', 'currency_code' => 'GBP']);
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
            'name' => 'Supplier',
            'party_types' => ['VENDOR'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'North',
            'status' => 'ACTIVE',
        ]);

        $inv = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'reference_no' => 'P45-INV',
            'invoice_date' => '2024-04-01',
            'total_amount' => 75.00,
            'lines' => [['description' => 'Seed', 'line_total' => 75.00]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv['id'].'/post', [
            'posting_date' => '2024-04-10',
            'idempotency_key' => 'p45-post-1',
        ])->assertCreated();

        $filters = ['from' => '2024-04-01', 'to' => '2024-04-30'];
        $svc = app(ProjectProfitabilityService::class);
        $profit = $svc->getProjectProfitability($project->id, $tenant->id, $filters);

        $this->assertEqualsWithDelta(75.00, $profit['totals']['cost'], 0.05);
        $this->assertEqualsWithDelta(-75.00, $profit['totals']['profit'], 0.05);

        $pl = app(ProjectPLQueryService::class)->getProjectPlRows(
            $tenant->id,
            $filters['from'],
            $filters['to'],
            $project->id,
            null
        );
        $this->assertCount(1, $pl);
        $this->assertEqualsWithDelta(75.00, (float) $pl[0]['expenses'], 0.05);
        $this->assertEqualsWithDelta((float) $pl[0]['net_profit'], $profit['totals']['profit'], 0.02);

        $api = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/project-profitability?project_id='.$project->id.'&from='.$filters['from'].'&to='.$filters['to']
        )->assertOk()->json();

        $this->assertArrayHasKey('_meta', $api);
        $this->assertSame('project_allocation_ledger', $api['_meta']['basis']);
        $this->assertEqualsWithDelta(75.00, $api['totals']['cost'], 0.05);
    }

    public function test_draft_supplier_invoice_not_in_profitability(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P45 T2', 'status' => 'active', 'currency_code' => 'GBP']);
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
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'reference_no' => 'DRAFT',
            'invoice_date' => '2024-05-01',
            'total_amount' => 500.00,
            'lines' => [['description' => 'X', 'line_total' => 500.00]],
        ])->assertCreated();

        $profit = app(ProjectProfitabilityService::class)->getProjectProfitability(
            $project->id,
            $tenant->id,
            ['from' => '2024-05-01', 'to' => '2024-05-31']
        );

        $this->assertEqualsWithDelta(0.0, $profit['totals']['cost'], 0.02);
    }

    public function test_cost_center_only_bill_not_in_project_profitability(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P45 T3', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'HQ',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        $farm = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'OH',
            'invoice_date' => '2024-06-01',
            'total_amount' => 200.00,
            'lines' => [['description' => 'Rent', 'line_total' => 200.00]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$farm['id'].'/post', [
            'posting_date' => '2024-06-15',
            'idempotency_key' => 'p45-oh',
        ])->assertCreated();

        $profit = app(ProjectProfitabilityService::class)->getProjectProfitability(
            $project->id,
            $tenant->id,
            ['from' => '2024-06-01', 'to' => '2024-06-30']
        );

        $this->assertEqualsWithDelta(0.0, $profit['totals']['cost'], 0.02);

        $pl = app(ProjectPLQueryService::class)->getProjectPlRows(
            $tenant->id,
            '2024-06-01',
            '2024-06-30',
            $project->id,
            null
        );
        $this->assertSame([], $pl);
    }

    public function test_eligible_posting_group_ids_matches_pl_query_service(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P45 T4', 'status' => 'active', 'currency_code' => 'GBP']);
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
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'P',
            'status' => 'ACTIVE',
        ]);

        $inv = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'reference_no' => 'X',
            'invoice_date' => '2024-07-01',
            'total_amount' => 10.00,
            'lines' => [['description' => 'L', 'line_total' => 10.00]],
        ])->assertCreated()->json();
        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv['id'].'/post', [
            'posting_date' => '2024-07-05',
            'idempotency_key' => 'p45-elig',
        ])->assertCreated();

        $pgSvc = app(ProjectPLQueryService::class);
        $idsA = $pgSvc->getEligiblePostingGroupIdsForProject($tenant->id, $project->id, '2024-07-01', '2024-07-31', null);

        $svc = app(ProjectProfitabilityService::class);
        $m = new \ReflectionMethod($svc, 'eligiblePostingGroupIds');
        $m->setAccessible(true);
        $idsB = $m->invoke($svc, $project->id, $tenant->id, '2024-07-01', '2024-07-31', null);

        $this->assertSame($idsA, $idsB);
    }
}
