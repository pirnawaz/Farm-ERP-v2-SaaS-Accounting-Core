<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use Database\Seeders\ModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettlementPackPhase1ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        \App\Services\TenantContext::clear();
        parent::setUp();
    }

    private function enableModules(Tenant $tenant, array $keys): void
    {
        (new ModulesSeeder)->run();
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

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_phase1_exports_return_csv_and_pdf(): void
    {
        $tenant = Tenant::create(['name' => 'SP1Export', 'status' => 'active', 'currency_code' => 'GBP']);
        $this->enableModules($tenant, ['reports', 'projects_crop_cycles']);

        $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'party_types' => ['HARI']]);
        $cc = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cc->id,
            'name' => 'Field',
            'status' => 'ACTIVE',
        ]);

        $csv = $this->withHeaders($this->headers($tenant))->get(
            '/api/reports/settlement-pack/project/export/summary.csv?project_id='.$project->id.'&from=2026-01-01&to=2026-01-31'
        );
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $pdf = $this->withHeaders($this->headers($tenant))->get(
            '/api/reports/settlement-pack/project/export/pack.pdf?project_id='.$project->id.'&from=2026-01-01&to=2026-01-31'
        );
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
    }
}

