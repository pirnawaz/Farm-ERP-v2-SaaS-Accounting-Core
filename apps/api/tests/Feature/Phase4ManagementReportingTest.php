<?php

namespace Tests\Feature;

use App\Models\CostCenter;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase4ManagementReportingTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_overheads_includes_posted_cost_center_bill_excludes_draft_and_project_scoped(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'R4 T', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $cc = CostCenter::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'status' => CostCenter::STATUS_ACTIVE,
        ]);
        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Utility Co',
            'party_types' => ['VENDOR'],
        ]);

        $posted = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'ELEC-1',
            'invoice_date' => '2024-02-01',
            'total_amount' => 120.00,
            'lines' => [['description' => 'Electricity', 'line_total' => 120.00]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$posted['id'].'/post', [
            'posting_date' => '2024-02-15',
            'idempotency_key' => 'r4-post-1',
        ])->assertCreated();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'DRAFT-ONLY',
            'invoice_date' => '2024-02-10',
            'total_amount' => 999.00,
            'lines' => [['description' => 'Draft', 'line_total' => 999.00]],
        ])->assertCreated();

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
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        $projInv = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'reference_no' => 'PROJ-SEED',
            'invoice_date' => '2024-02-05',
            'total_amount' => 40.00,
            'lines' => [['description' => 'Inputs', 'line_total' => 40.00]],
        ])->assertCreated()->json();

        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$projInv['id'].'/post', [
            'posting_date' => '2024-02-06',
            'idempotency_key' => 'r4-proj-1',
        ])->assertCreated();

        $oh = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/overheads?from=2024-02-01&to=2024-02-28'
        )->assertOk()->json();

        $this->assertSame('2024-02-01', $oh['period']['from']);
        $this->assertCount(1, $oh['by_cost_center']);
        $this->assertSame($cc->id, $oh['by_cost_center'][0]['cost_center_id']);
        $this->assertEqualsWithDelta(120.00, (float) $oh['by_cost_center'][0]['expenses'], 0.05);
        $this->assertStringContainsString('-', $oh['by_cost_center'][0]['net']);
        $this->assertEqualsWithDelta(120.00, (float) $oh['grand_totals']['expenses'], 0.05);

        $byAcct = collect($oh['by_account']);
        $inputs = $byAcct->firstWhere('account_code', 'INPUTS_EXPENSE');
        $this->assertNotNull($inputs);
        $this->assertEqualsWithDelta(120.00, (float) $inputs['expenses'], 0.05);

        $filtered = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/overheads?from=2024-02-01&to=2024-02-28&party_id='.$vendor->id
        )->assertOk()->json();
        $this->assertEqualsWithDelta(120.00, (float) $filtered['grand_totals']['expenses'], 0.05);

        $otherParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Other',
            'party_types' => ['VENDOR'],
        ]);
        $emptyParty = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/overheads?from=2024-02-01&to=2024-02-28&party_id='.$otherParty->id
        )->assertOk()->json();
        $this->assertSame([], $emptyParty['by_cost_center']);
        $this->assertEqualsWithDelta(0.0, (float) $emptyParty['grand_totals']['expenses'], 0.02);
    }

    public function test_farm_pnl_combines_project_pl_and_overhead_no_double_count(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'R4 T2', 'status' => 'active', 'currency_code' => 'GBP']);
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
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $projInv = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'project_id' => $project->id,
            'cost_center_id' => null,
            'reference_no' => 'PINV',
            'invoice_date' => '2024-03-01',
            'total_amount' => 50.00,
            'lines' => [['description' => 'Seed', 'line_total' => 50.00]],
        ])->assertCreated()->json();
        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$projInv['id'].'/post', [
            'posting_date' => '2024-03-10',
            'idempotency_key' => 'r4-pinv',
        ])->assertCreated();

        $farmBill = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
            'party_id' => $vendor->id,
            'cost_center_id' => $cc->id,
            'project_id' => null,
            'reference_no' => 'OH',
            'invoice_date' => '2024-03-02',
            'total_amount' => 30.00,
            'lines' => [['description' => 'Internet', 'line_total' => 30.00]],
        ])->assertCreated()->json();
        $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$farmBill['id'].'/post', [
            'posting_date' => '2024-03-12',
            'idempotency_key' => 'r4-oh',
        ])->assertCreated();

        $pnl = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from=2024-03-01&to=2024-03-31'
        )->assertOk()->json();

        $projNet = (float) $pnl['projects']['totals']['net_profit'];
        $ohNet = (float) $pnl['overhead']['grand_totals']['net'];
        $farmNet = (float) $pnl['combined']['net_farm_operating_result'];

        $this->assertEqualsWithDelta(-50.00, $projNet, 0.05, 'Project supplier invoice should hit project P&L as expense');
        $this->assertEqualsWithDelta(-30.00, $ohNet, 0.05, 'Overhead net should be negative expense');
        $this->assertEqualsWithDelta($projNet + $ohNet, $farmNet, 0.02);

        $filtered = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from=2024-03-01&to=2024-03-31&crop_cycle_id='.$cycle->id
        )->assertOk()->json();
        $this->assertCount(1, $filtered['projects']['rows']);
        $this->assertSame($project->id, $filtered['projects']['rows'][0]['project_id']);
        $this->assertEqualsWithDelta(-50.00, (float) $filtered['projects']['totals']['net_profit'], 0.05);
        $this->assertEqualsWithDelta(-30.00, (float) $filtered['overhead']['grand_totals']['net'], 0.05);
    }

    public function test_overheads_and_farm_pnl_reject_invalid_dates(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'R4 T3', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/overheads?from=2024-04-10&to=2024-04-01'
        )->assertStatus(422);

        $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/farm-pnl?from=2024-04-10&to=2024-04-01'
        )->assertStatus(422);
    }

    public function test_farm_pnl_rejects_foreign_crop_cycle(): void
    {
        (new ModulesSeeder)->run();
        $t1 = Tenant::create(['name' => 'A', 'status' => 'active', 'currency_code' => 'GBP']);
        $t2 = Tenant::create(['name' => 'B', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($t1->id);
        SystemAccountsSeeder::runForTenant($t2->id);

        $cycleB = CropCycle::create([
            'tenant_id' => $t2->id,
            'name' => 'B-cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->withHeaders($this->headers($t1))->getJson(
            '/api/reports/farm-pnl?from=2024-01-01&to=2024-12-31&crop_cycle_id='.$cycleB->id
        )->assertStatus(422);
    }

    public function test_project_pl_crop_cycle_filter_matches_farm_pnl_project_side(): void
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'R4 T4', 'status' => 'active', 'currency_code' => 'GBP']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $vendor = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'V',
            'party_types' => ['VENDOR'],
        ]);
        $c2024 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $c2025 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'OPEN',
        ]);
        $p2024 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $c2024->id,
            'name' => 'P2024',
            'status' => 'ACTIVE',
        ]);
        $p2025 = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $vendor->id,
            'crop_cycle_id' => $c2025->id,
            'name' => 'P2025',
            'status' => 'ACTIVE',
        ]);

        foreach ([[$p2024, 'inv24', '2024-05-01'], [$p2025, 'inv25', '2025-05-01']] as [$proj, $ref, $date]) {
            $inv = $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices', [
                'party_id' => $vendor->id,
                'project_id' => $proj->id,
                'cost_center_id' => null,
                'reference_no' => $ref,
                'invoice_date' => $date,
                'total_amount' => 10.00,
                'lines' => [['description' => 'x', 'line_total' => 10.00]],
            ])->assertCreated()->json();
            $this->withHeaders($this->headers($tenant))->postJson('/api/supplier-invoices/'.$inv['id'].'/post', [
                'posting_date' => $date,
                'idempotency_key' => 'cc-'.$ref,
            ])->assertCreated();
        }

        $plAll = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/project-pl?from=2024-01-01&to=2025-12-31'
        )->assertOk()->json();
        $this->assertCount(2, $plAll);

        $pl2024 = $this->withHeaders($this->headers($tenant))->getJson(
            '/api/reports/project-pl?from=2024-01-01&to=2025-12-31&crop_cycle_id='.$c2024->id
        )->assertOk()->json();
        $this->assertCount(1, $pl2024);
        $this->assertSame($p2024->id, $pl2024[0]['project_id']);
    }
}
