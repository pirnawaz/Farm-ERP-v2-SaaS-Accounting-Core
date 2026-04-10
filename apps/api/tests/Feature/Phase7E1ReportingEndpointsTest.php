<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\TenantContext;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase7E1ReportingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function enableReports(Tenant $tenant): void
    {
        $module = Module::where('key', 'reports')->first();
        if ($module) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $module->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function authHeaders(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_machine_profitability_requires_date_range(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableReports($tenant);

        $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/reports/machine-profitability')
            ->assertStatus(422);
    }

    public function test_machine_profitability_returns_json_array(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableReports($tenant);

        $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/reports/machine-profitability?from=2024-01-01&to=2024-12-31')
            ->assertStatus(200)
            ->assertJsonIsArray();
    }

    public function test_harvest_economics_list_requires_date_range(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableReports($tenant);

        $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/reports/harvest-economics')
            ->assertStatus(422);
    }

    public function test_harvest_economics_list_returns_paginator_shape(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableReports($tenant);

        $res = $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/reports/harvest-economics?from=2024-01-01&to=2024-12-31');

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'data',
            'links',
            'current_page',
            'per_page',
            'total',
        ]);
    }

    public function test_project_profitability_rejects_crop_cycle_not_matching_project(): void
    {
        TenantContext::clear();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableReports($tenant);

        $cc1 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $cc2 = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C2',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'P',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'name' => 'Proj',
            'crop_cycle_id' => $cc1->id,
            'party_id' => $party->id,
        ]);

        $this->withHeaders($this->authHeaders($tenant))
            ->getJson('/api/reports/project-profitability?project_id=' . $project->id . '&crop_cycle_id=' . $cc2->id)
            ->assertStatus(422);
    }
}
