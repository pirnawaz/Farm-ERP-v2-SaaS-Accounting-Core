<?php

namespace Tests\Unit;

use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\ProjectPlanCost;
use App\Models\ProjectPlanYield;
use App\Models\Tenant;
use App\Services\ForecastService;
use App\Services\ProjectProfitabilityService;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 8G.1 — ForecastService variance and projected profit with controlled profitability totals (mocked).
 */
class ForecastServicePhase8G1Test extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, project: Project, plan: ProjectPlan}
     */
    private function seedPlanWithKnownPlannedTotals(): array
    {
        $tenant = Tenant::create(['name' => 'T-FS-'.uniqid(), 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'P', 'party_types' => ['HARI']]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C',
            'start_date' => '2024-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'F',
            'status' => 'ACTIVE',
        ]);

        $plan = ProjectPlan::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Active',
            'status' => ProjectPlan::STATUS_ACTIVE,
        ]);
        ProjectPlanCost::create([
            'project_plan_id' => $plan->id,
            'cost_type' => ProjectPlanCost::COST_TYPE_INPUT,
            'expected_cost' => 100,
        ]);
        ProjectPlanYield::create([
            'project_plan_id' => $plan->id,
            'expected_quantity' => 20,
            'expected_unit_value' => 10,
        ]);

        // Planned: cost 100, revenue 200, profit 100

        return ['tenant' => $tenant, 'project' => $project, 'plan' => $plan];
    }

    private function profitabilityStub(float $revenue, float $cost, float $profit): array
    {
        return [
            'revenue' => ['sales' => $revenue, 'machinery_income' => 0.0, 'in_kind_income' => 0.0],
            'costs' => ['inputs' => $cost, 'labour' => 0.0, 'machinery' => 0.0, 'landlord' => 0.0],
            'totals' => ['revenue' => $revenue, 'cost' => $cost, 'profit' => $profit],
        ];
    }

    public function test_forecast_variance_matches_actual_minus_planned_with_mocked_actuals(): void
    {
        ['project' => $project] = $this->seedPlanWithKnownPlannedTotals();

        $mock = Mockery::mock(ProjectProfitabilityService::class);
        $mock->shouldReceive('getProjectProfitability')
            ->once()
            ->andReturn($this->profitabilityStub(55.5, 40.25, 15.25));

        $this->app->instance(ProjectProfitabilityService::class, $mock);

        /** @var ForecastService $svc */
        $svc = $this->app->make(ForecastService::class);
        $out = $svc->getProjectForecast($project->id, ['from' => '2024-01-01', 'to' => '2024-12-31']);

        $this->assertEqualsWithDelta(100.0, $out['planned']['cost'], 0.01);
        $this->assertEqualsWithDelta(200.0, $out['planned']['revenue'], 0.01);
        $this->assertEqualsWithDelta(100.0, $out['planned']['profit'], 0.01);

        $this->assertEqualsWithDelta(55.5, $out['actual']['revenue'], 0.01);
        $this->assertEqualsWithDelta(40.25, $out['actual']['cost'], 0.01);
        $this->assertEqualsWithDelta(15.25, $out['actual']['profit'], 0.01);

        $this->assertEqualsWithDelta(-144.5, $out['variance']['revenue'], 0.01);
        $this->assertEqualsWithDelta(-59.75, $out['variance']['cost'], 0.01);
        $this->assertEqualsWithDelta(-84.75, $out['variance']['profit'], 0.01);

        foreach (['cost', 'revenue', 'profit'] as $k) {
            $this->assertEqualsWithDelta(
                $out['actual'][$k] - $out['planned'][$k],
                $out['variance'][$k],
                0.02,
                $k
            );
        }
    }

    public function test_projected_profitability_subtracts_mocked_cost_from_planned_yield_value(): void
    {
        ['project' => $project] = $this->seedPlanWithKnownPlannedTotals();

        $mock = Mockery::mock(ProjectProfitabilityService::class);
        $mock->shouldReceive('getProjectProfitability')
            ->once()
            ->andReturn($this->profitabilityStub(0.0, 35.0, -35.0));

        $this->app->instance(ProjectProfitabilityService::class, $mock);

        /** @var ForecastService $svc */
        $svc = $this->app->make(ForecastService::class);
        $out = $svc->getProjectedProfitability($project->id, ['to' => '2024-06-30']);

        $this->assertEqualsWithDelta(200.0, $out['projected_revenue'], 0.01);
        $this->assertEqualsWithDelta(35.0, $out['projected_cost'], 0.01);
        $this->assertEqualsWithDelta(165.0, $out['projected_profit'], 0.01);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
