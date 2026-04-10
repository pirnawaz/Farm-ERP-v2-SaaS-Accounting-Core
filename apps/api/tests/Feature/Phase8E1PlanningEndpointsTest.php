<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectPlan;
use App\Models\ProjectPlanCost;
use App\Models\ProjectPlanYield;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8E.1 — Planning & forecast HTTP endpoints (tenant-scoped, filterable).
 */
class Phase8E1PlanningEndpointsTest extends TestCase
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
    private function seedProject(): array
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'P8E1-'.uniqid(), 'status' => 'active']);
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

    public function test_plans_project_index_and_store(): void
    {
        ['tenant' => $tenant, 'cc' => $cc, 'project' => $project] = $this->seedProject();
        $headers = $this->auth($tenant);

        $this->withHeaders($headers)
            ->getJson('/api/plans/project')
            ->assertStatus(200)
            ->assertJsonCount(0);

        $payload = [
            'name' => 'Plan v1',
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'status' => ProjectPlan::STATUS_ACTIVE,
            'costs' => [
                ['cost_type' => ProjectPlanCost::COST_TYPE_INPUT, 'expected_cost' => 1000],
            ],
            'yields' => [
                ['expected_quantity' => 100, 'expected_unit_value' => 5],
            ],
        ];

        $this->withHeaders($headers)
            ->postJson('/api/plans/project', $payload)
            ->assertStatus(201)
            ->assertJsonPath('name', 'Plan v1')
            ->assertJsonPath('status', ProjectPlan::STATUS_ACTIVE);

        $this->withHeaders($headers)
            ->getJson('/api/plans/project?project_id='.$project->id)
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_project_forecast_and_projected_profit_require_project_id(): void
    {
        ['tenant' => $tenant] = $this->seedProject();
        $headers = $this->auth($tenant);

        $this->withHeaders($headers)
            ->getJson('/api/reports/project-forecast')
            ->assertStatus(422);

        $this->withHeaders($headers)
            ->getJson('/api/reports/project-projected-profit')
            ->assertStatus(422);
    }

    public function test_project_forecast_returns_planned_actual_variance_shape(): void
    {
        ['tenant' => $tenant, 'cc' => $cc, 'project' => $project] = $this->seedProject();
        $headers = $this->auth($tenant);

        $plan = ProjectPlan::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'P',
            'status' => ProjectPlan::STATUS_ACTIVE,
        ]);
        ProjectPlanYield::create([
            'project_plan_id' => $plan->id,
            'expected_quantity' => 10,
            'expected_unit_value' => 20,
        ]);
        ProjectPlanCost::create([
            'project_plan_id' => $plan->id,
            'cost_type' => ProjectPlanCost::COST_TYPE_INPUT,
            'expected_cost' => 50,
        ]);

        $this->withHeaders($headers)
            ->getJson('/api/reports/project-forecast?project_id='.$project->id)
            ->assertStatus(200)
            ->assertJsonStructure([
                'planned' => ['cost', 'revenue', 'profit'],
                'actual' => ['cost', 'revenue', 'profit'],
                'variance' => ['cost', 'revenue', 'profit'],
            ]);
    }

    public function test_project_projected_profit_returns_keys(): void
    {
        ['tenant' => $tenant, 'project' => $project] = $this->seedProject();
        $headers = $this->auth($tenant);

        $this->withHeaders($headers)
            ->getJson('/api/reports/project-projected-profit?project_id='.$project->id)
            ->assertStatus(200)
            ->assertJsonStructure([
                'projected_revenue',
                'projected_cost',
                'projected_profit',
            ]);
    }
}
