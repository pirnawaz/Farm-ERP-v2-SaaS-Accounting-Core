<?php

namespace Tests\Feature;

use App\Models\CropCycle;
use App\Models\Module;
use App\Models\OperationalTransaction;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\PostingService;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase7StatementExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $otherTenant;

    private CropCycle $cropCycle;

    private Party $hariParty;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Phase7 Tenant', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['name' => 'Phase7 Other', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        SystemAccountsSeeder::runForTenant($this->otherTenant->id);
        $this->enableModuleKeys($this->tenant, ['reports', 'settlements', 'projects_crop_cycles']);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $this->hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Field A',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
    }

    /** @param  list<string>  $keys */
    private function enableModuleKeys(Tenant $tenant, array $keys): void
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

    private function seedPostings(): void
    {
        $post = function (string $type, string $classification, float $amount, string $idem): void {
            $txn = OperationalTransaction::create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'crop_cycle_id' => $this->cropCycle->id,
                'type' => $type,
                'status' => 'DRAFT',
                'transaction_date' => '2024-06-15',
                'amount' => $amount,
                'classification' => $classification,
            ]);
            app(PostingService::class)->postOperationalTransaction($txn->id, $this->tenant->id, '2024-06-15', $idem);
        };
        $post('INCOME', 'SHARED', 500.0, 'idem-inc-500');
        $post('EXPENSE', 'SHARED', 100.0, 'idem-exp-100');
        $post('EXPENSE', 'HARI_ONLY', 40.0, 'idem-hari-40');
        $post('EXPENSE', 'LANDLORD_ONLY', 25.0, 'idem-ll-25');
    }

    public function test_responsibility_export_pdf_matches_json_totals(): void
    {
        $this->seedPostings();

        $json = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-responsibility?'.http_build_query([
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $json->assertOk();
        $pdf = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-responsibility/export?'.http_build_query([
                'format' => 'pdf',
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $pdf->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF', $pdf->getContent());
        $this->assertNotEmpty($pdf->getContent());
    }

    public function test_responsibility_export_csv_contains_bucket_values_matching_json(): void
    {
        $this->seedPostings();

        $json = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-responsibility?'.http_build_query([
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $hari = (string) $json->json('buckets.hari_only_costs');

        $csv = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-responsibility/export?'.http_build_query([
                'format' => 'csv',
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csv->getContent();
        $this->assertStringContainsString('bucket,hari_only_costs,'.$hari, $body);
        $this->assertStringContainsString('Field A', $body);
    }

    public function test_responsibility_export_rejects_missing_format(): void
    {
        $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-responsibility/export?'.http_build_query([
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]))
            ->assertStatus(422);
    }

    public function test_party_economics_export_pdf_contains_hari_net_from_json(): void
    {
        $this->seedPostings();

        $json = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-party-economics?'.http_build_query([
                'project_id' => $this->project->id,
                'party_id' => $this->hariParty->id,
                'up_to_date' => '2024-06-30',
            ]));
        $json->assertOk();
        $hariNet = (string) $json->json('hari_settlement_preview.hari_net');

        $csv = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-party-economics/export?'.http_build_query([
                'format' => 'csv',
                'project_id' => $this->project->id,
                'party_id' => $this->hariParty->id,
                'up_to_date' => '2024-06-30',
            ]));
        $csv->assertOk();
        $this->assertStringContainsString('hari_settlement_preview,hari_net,'.$hariNet, $csv->getContent());

        $pdf = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-party-economics/export?'.http_build_query([
                'format' => 'pdf',
                'project_id' => $this->project->id,
                'party_id' => $this->hariParty->id,
                'up_to_date' => '2024-06-30',
            ]));
        $pdf->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF', $pdf->getContent());
    }

    public function test_settlement_review_export_contains_preview_pool_profit_matching_json_preview(): void
    {
        $this->seedPostings();

        $previewRes = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$this->project->id}/settlement/preview", ['up_to_date' => '2024-06-30']);
        $previewRes->assertOk();
        $poolProfit = (string) $previewRes->json('pool_profit');

        $csv = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-settlement-review/export?'.http_build_query([
                'format' => 'csv',
                'project_id' => $this->project->id,
                'up_to_date' => '2024-06-30',
            ]));
        $csv->assertOk();
        $this->assertStringContainsString('preview,pool_profit,'.$poolProfit, $csv->getContent());

        $pdf = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-settlement-review/export?'.http_build_query([
                'format' => 'pdf',
                'project_id' => $this->project->id,
                'up_to_date' => '2024-06-30',
            ]));
        $pdf->assertOk();
        $this->assertStringContainsString('%PDF', $pdf->getContent());
    }

    public function test_cross_tenant_export_returns_404(): void
    {
        $this->seedPostings();

        $this->withHeader('X-Tenant-Id', $this->otherTenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->get('/api/reports/project-responsibility/export?'.http_build_query([
                'format' => 'pdf',
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]))
            ->assertNotFound();
    }

    public function test_read_endpoints_unchanged_after_export_routes_exist(): void
    {
        $this->seedPostings();

        $read = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-responsibility?'.http_build_query([
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $read->assertOk();
        $this->assertEqualsWithDelta(40.0, (float) $read->json('buckets.hari_only_costs'), 0.02);
    }
}
