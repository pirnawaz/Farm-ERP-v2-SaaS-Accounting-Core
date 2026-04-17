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
use App\Services\ProjectResponsibilityReadService;
use App\Services\SettlementService;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6ProjectResponsibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Party $hariParty;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Phase6 Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->enableModuleKeys(['reports', 'settlements', 'projects_crop_cycles']);

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
    private function enableModuleKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $this->tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    private function postIncome(float $amount): void
    {
        $txn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-01',
            'amount' => $amount,
            'classification' => 'SHARED',
        ]);
        app(PostingService::class)->postOperationalTransaction($txn->id, $this->tenant->id, '2024-06-01', 'idem-income-'.$amount);
    }

    private function postExpense(string $classification, float $amount, string $idemSuffix): void
    {
        $txn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => $amount,
            'classification' => $classification,
        ]);
        app(PostingService::class)->postOperationalTransaction($txn->id, $this->tenant->id, '2024-06-15', 'idem-'.$idemSuffix);
    }

    public function test_project_responsibility_report_buckets_match_posted_scopes(): void
    {
        $this->postIncome(500.0);
        $this->postExpense('SHARED', 100.0, 'shared');
        $this->postExpense('HARI_ONLY', 40.0, 'hari');
        $this->postExpense('LANDLORD_ONLY', 25.0, 'll');

        $svc = app(ProjectResponsibilityReadService::class);
        $report = $svc->summarizeForProjectPeriod(
            $this->tenant->id,
            $this->project->id,
            '2024-01-01',
            '2024-12-31',
            null,
        );

        $this->assertGreaterThan(0, $report['posting_groups_count']);
        $this->assertEqualsWithDelta(100.0, (float) $report['buckets']['settlement_shared_pool_costs'], 0.02);
        $this->assertEqualsWithDelta(40.0, (float) $report['buckets']['hari_only_costs'], 0.02);
        $this->assertEqualsWithDelta(25.0, (float) $report['buckets']['landlord_only_costs'], 0.02);
        $this->assertArrayHasKey('SHARED', $report['by_effective_responsibility']);
        $this->assertSame('project_rule', $report['settlement_terms']['resolution_source']);
    }

    public function test_settlement_preview_numeric_unchanged_and_includes_party_economics_explanation(): void
    {
        $this->postIncome(200.0);
        $this->postExpense('SHARED', 60.0, 's');
        $this->postExpense('HARI_ONLY', 20.0, 'h');

        $settlement = app(SettlementService::class);
        $before = $settlement->previewSettlement($this->project->id, $this->tenant->id, '2024-06-30');

        $resp = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$this->project->id}/settlement/preview", ['up_to_date' => '2024-06-30']);

        $resp->assertOk();
        $json = $resp->json();
        $this->assertArrayHasKey('party_economics_explanation', $json);
        $this->assertEqualsWithDelta((float) $before['hari_net'], (float) $json['hari_net'], 0.01);
        $this->assertEqualsWithDelta((float) $before['pool_profit'], (float) $json['pool_profit'], 0.01);
        $this->assertArrayHasKey('recoverability', $json['party_economics_explanation']);
    }

    public function test_project_responsibility_api_requires_project_and_dates(): void
    {
        $resp = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-responsibility?'.http_build_query([
                'project_id' => $this->project->id,
                'from' => '2024-01-01',
                'to' => '2024-12-31',
            ]));
        $resp->assertOk();
        $resp->assertJsonPath('project_id', $this->project->id);
        $resp->assertJsonPath('settlement_terms.resolution_source', 'project_rule');
    }

    public function test_project_party_economics_for_hari_includes_preview_slice(): void
    {
        $this->postIncome(300.0);
        $this->postExpense('SHARED', 50.0, 'x');

        $resp = $this->withHeader('X-Tenant-Id', $this->tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/reports/project-party-economics?'.http_build_query([
                'project_id' => $this->project->id,
                'party_id' => $this->hariParty->id,
                'up_to_date' => '2024-06-30',
            ]));

        $resp->assertOk();
        $resp->assertJsonPath('is_project_hari_party', true);
        $this->assertArrayHasKey('hari_settlement_preview', $resp->json());
        $resp->assertJsonPath('settlement_terms.resolution_source', 'project_rule');
    }

    public function test_legacy_unscoped_row_classified_via_allocation_type(): void
    {
        $pg = \App\Models\PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => \Illuminate\Support\Str::uuid()->toString(),
            'posting_date' => '2024-06-10',
            'idempotency_key' => 'legacy-scope-test-'.uniqid(),
        ]);

        \App\Models\AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $this->project->id,
            'party_id' => $this->hariParty->id,
            'allocation_type' => 'POOL_SHARE',
            'allocation_scope' => null,
            'amount' => '12.34',
        ]);

        $svc = app(ProjectResponsibilityReadService::class);
        $report = $svc->summarizeForProjectPeriod(
            $this->tenant->id,
            $this->project->id,
            '2024-06-01',
            '2024-06-30',
            null,
        );

        $this->assertArrayHasKey('SHARED', $report['by_effective_responsibility']);
        $this->assertEqualsWithDelta(12.34, (float) $report['by_effective_responsibility']['SHARED'], 0.02);
    }
}
