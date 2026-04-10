<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\ProjectPlanCost;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Carbon\Carbon;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 8G.1 — Deterministic planning tests: budget creation, forecast planned totals,
 * variance = actual − planned, projected profitability (planned yield value vs posted cost slice).
 */
class Phase8G1PlanningDeterminismTest extends TestCase
{
    use RefreshDatabase;

    private function enableModules(Tenant $tenant, array $keys): void
    {
        foreach ($keys as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    private function auth(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    /**
     * @return array{tenant: Tenant, cc: CropCycle, project: Project}
     */
    private function seedTenantProject(): array
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P8G1-'.uniqid(), 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant, ['projects_crop_cycles', 'reports']);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        return ['tenant' => $tenant, 'cc' => $cc, 'project' => $project];
    }

    public function test_budget_creation_stores_expected_costs_and_yield_lines(): void
    {
        ['tenant' => $tenant, 'cc' => $cc, 'project' => $project] = $this->seedTenantProject();
        $headers = $this->auth($tenant);

        $payload = [
            'name' => 'Deterministic plan',
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'status' => ProjectPlan::STATUS_ACTIVE,
            'costs' => [
                ['cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 100.5],
                ['cost_type' => ProjectPlanCost::COST_TYPE_LABOUR, 'expected_cost' => 200],
                ['cost_type' => ProjectPlanCost::COST_TYPE_MACHINERY, 'expected_cost' => 50.25],
            ],
            'yields' => [
                ['expected_quantity' => 10, 'expected_unit_value' => 5],
                ['expected_quantity' => 3, 'expected_unit_value' => 20],
            ],
        ];

        $res = $this->withHeaders($headers)->postJson('/api/plans/project', $payload);
        $res->assertStatus(201);
        $planId = $res->json('id');

        $this->assertNotEmpty($planId);
        $this->assertDatabaseHas('project_plans', [
            'id' => $planId,
            'name' => 'Deterministic plan',
            'status' => ProjectPlan::STATUS_ACTIVE,
        ]);

        $this->assertSame(3, (int) DB::table('project_plan_costs')->where('project_plan_id', $planId)->count());
        $this->assertSame(2, (int) DB::table('project_plan_yields')->where('project_plan_id', $planId)->count());

        $sumCost = (float) DB::table('project_plan_costs')->where('project_plan_id', $planId)->sum('expected_cost');
        $this->assertEqualsWithDelta(350.75, $sumCost, 0.001);
    }

    public function test_forecast_planned_totals_and_variance_match_definitions_with_no_postings(): void
    {
        ['tenant' => $tenant, 'cc' => $cc, 'project' => $project] = $this->seedTenantProject();
        $headers = $this->auth($tenant);

        // Planned: cost 350, revenue 10*5 + 3*20 = 110, profit -240
        $this->withHeaders($headers)->postJson('/api/plans/project', [
            'name' => 'Plan',
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'status' => ProjectPlan::STATUS_ACTIVE,
            'costs' => [
                ['cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 100],
                ['cost_type' => ProjectPlanCost::COST_TYPE_LABOUR, 'expected_cost' => 200],
                ['cost_type' => ProjectPlanCost::COST_TYPE_MACHINERY, 'expected_cost' => 50],
            ],
            'yields' => [
                ['expected_quantity' => 10, 'expected_unit_value' => 5],
                ['expected_quantity' => 3, 'expected_unit_value' => 20],
            ],
        ])->assertStatus(201);

        $r = $this->withHeaders($headers)
            ->getJson('/api/reports/project-forecast?project_id='.$project->id.'&from=2024-01-01&to=2024-12-31')
            ->assertStatus(200)
            ->json();

        $this->assertEqualsWithDelta(350.0, $r['planned']['cost'], 0.01);
        $this->assertEqualsWithDelta(110.0, $r['planned']['revenue'], 0.01);
        $this->assertEqualsWithDelta(-240.0, $r['planned']['profit'], 0.01);

        $this->assertEqualsWithDelta(0.0, $r['actual']['cost'], 0.01);
        $this->assertEqualsWithDelta(0.0, $r['actual']['revenue'], 0.01);
        $this->assertEqualsWithDelta(0.0, $r['actual']['profit'], 0.01);

        $this->assertEqualsWithDelta(-350.0, $r['variance']['cost'], 0.01);
        $this->assertEqualsWithDelta(-110.0, $r['variance']['revenue'], 0.01);
        $this->assertEqualsWithDelta(240.0, $r['variance']['profit'], 0.01);

        foreach (['cost', 'revenue', 'profit'] as $k) {
            $this->assertEqualsWithDelta(
                $r['actual'][$k] - $r['planned'][$k],
                $r['variance'][$k],
                0.02,
                'variance['.$k.'] must equal actual['.$k.'] − planned['.$k.']'
            );
        }
    }

    public function test_projected_profitability_uses_planned_yield_value_and_posted_cost_slice(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-06-15', 'UTC'));

        try {
            ['tenant' => $tenant, 'cc' => $cc, 'project' => $project] = $this->seedTenantProject();
            $headers = $this->auth($tenant);

            $this->withHeaders($headers)->postJson('/api/plans/project', [
                'name' => 'Plan',
                'project_id' => $project->id,
                'crop_cycle_id' => $cc->id,
                'status' => ProjectPlan::STATUS_ACTIVE,
                'costs' => [
                    ['cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 80],
                ],
                'yields' => [
                    ['expected_quantity' => 4, 'expected_unit_value' => 25],
                ],
            ])->assertStatus(201);

            // No ledger postings → projected cost 0; projected revenue = 4 * 25 = 100
            $r = $this->withHeaders($headers)
                ->getJson('/api/reports/project-projected-profit?project_id='.$project->id)
                ->assertStatus(200)
                ->json();

            $this->assertEqualsWithDelta(100.0, $r['projected_revenue'], 0.01);
            $this->assertEqualsWithDelta(0.0, $r['projected_cost'], 0.01);
            $this->assertEqualsWithDelta(100.0, $r['projected_profit'], 0.01);
            $this->assertSame('2024-06-15', Carbon::now()->toDateString());

            $r2 = $this->withHeaders($headers)
                ->getJson('/api/reports/project-projected-profit?project_id='.$project->id.'&from=2024-01-01&to=2024-12-31')
                ->assertStatus(200)
                ->json();
            $this->assertEqualsWithDelta(100.0, $r2['projected_revenue'], 0.01);
            $this->assertEqualsWithDelta(0.0, $r2['projected_cost'], 0.01);
            $this->assertEqualsWithDelta(100.0, $r2['projected_profit'], 0.01);
        } finally {
            Carbon::setTestNow();
        }
    }
}
