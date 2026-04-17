<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Services\ProjectSettlementRuleResolver;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementPrimarySettlementPhase2Test extends TestCase
{
    use RefreshDatabase;

    private function enableCropOps(Tenant $tenant): void
    {
        $m = Module::where('key', 'crop_ops')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /** @return array{tenant: Tenant, cycle: CropCycle, hari: Party, landlord: Party} */
    private function seedTenantBasics(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Phase2 T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableCropOps($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $hari = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari P',
            'party_types' => ['HARI'],
        ]);

        $landlord = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord P',
            'party_types' => ['LANDLORD'],
        ]);

        return ['tenant' => $tenant, 'cycle' => $cycle, 'hari' => $hari, 'landlord' => $landlord];
    }

    private function apiHeaders(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_agreement_post_rejects_active_project_scoped_land_lease_without_settlement(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'P1',
            'status' => 'ACTIVE',
        ]);

        $res = $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/v1/crop-ops/agreements', [
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => ['basis' => 'NOTE', 'note' => 'no settlement'],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['terms']);
    }

    public function test_agreement_post_accepts_valid_settlement_block(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'P2',
            'status' => 'ACTIVE',
        ]);

        $res = $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/v1/crop-ops/agreements', [
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '40',
                    'profit_split_hari_pct' => '60',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $res->assertCreated();
    }

    public function test_legacy_agreement_without_settlement_still_readable_and_unrelated_put_allowed(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'P3',
            'status' => 'ACTIVE',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))
            ->getJson('/api/v1/crop-ops/agreements/' . $ag->id)
            ->assertOk();

        $this->withHeaders($this->apiHeaders($tenant))->putJson('/api/v1/crop-ops/agreements/' . $ag->id, [
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 5,
            'status' => Agreement::STATUS_ACTIVE,
        ])->assertOk();
    }

    public function test_agreement_put_rejects_when_terms_changed_to_invalid_for_project_scoped_active_land_lease(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'P4',
            'status' => 'ACTIVE',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '50',
                    'profit_split_hari_pct' => '50',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->putJson('/api/v1/crop-ops/agreements/' . $ag->id, [
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => ['settlement' => ['profit_split_landlord_pct' => '10', 'profit_split_hari_pct' => '10']],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ])->assertStatus(422)->assertJsonValidationErrors(['terms']);
    }

    public function test_resolver_prefers_agreement_over_project_rule(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'PR',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 10.00,
            'profit_split_hari_pct' => 90.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '62',
                    'profit_split_hari_pct' => '38',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
        $project->update(['agreement_id' => $ag->id]);

        $rule = app(ProjectSettlementRuleResolver::class)->resolveSettlementRule($project->fresh());
        $this->assertSame('agreement', $rule['resolution_source']);
        $this->assertSame('62.00', $rule['profit_split_landlord_pct']);
        $this->assertSame('38.00', $rule['profit_split_hari_pct']);
    }

    public function test_resolver_falls_back_to_project_rule_when_agreement_terms_missing(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'PF',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 15.00,
            'profit_split_hari_pct' => 85.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
        $project->update(['agreement_id' => $ag->id]);

        $rule = app(ProjectSettlementRuleResolver::class)->resolveSettlementRule($project->fresh());
        $this->assertSame('project_rule', $rule['resolution_source']);
        $this->assertSame('15.00', $rule['profit_split_landlord_pct']);
    }

    public function test_resolver_throws_when_agreement_linked_but_unusable_and_no_project_rule(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'PX',
            'status' => 'ACTIVE',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
        $project->update(['agreement_id' => $ag->id]);

        $this->expectException(\RuntimeException::class);
        app(ProjectSettlementRuleResolver::class)->resolveSettlementRule($project->fresh());
    }

    public function test_project_store_rejects_when_agreement_linked_without_resolvable_settlement(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/projects', [
            'name' => 'New Proj',
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'agreement_id' => $ag->id,
            'status' => 'ACTIVE',
        ])->assertStatus(422);
    }

    public function test_project_store_succeeds_when_agreement_has_settlement_without_project_rule(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '50',
                    'profit_split_hari_pct' => '50',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/projects', [
            'name' => 'New Proj B',
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'agreement_id' => $ag->id,
            'status' => 'ACTIVE',
        ])->assertCreated();
    }

    public function test_from_agreement_allocation_requires_agreement_settlement(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parc',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $alloc = AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $ag->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 20,
            'area_uom' => 'ACRE',
            'starts_on' => '2024-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/projects/from-agreement-allocation', [
            'agreement_allocation_id' => $alloc->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
        ])->assertStatus(422);
    }

    public function test_from_agreement_allocation_succeeds_without_project_rule(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parc2',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '55',
                    'profit_split_hari_pct' => '45',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $alloc = AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $ag->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 20,
            'area_uom' => 'ACRE',
            'starts_on' => '2024-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/projects/from-agreement-allocation', [
            'agreement_allocation_id' => $alloc->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
        ])->assertCreated();
    }

    public function test_project_store_rejects_mismatched_agreement_id_for_allocation(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parc3',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $ag1 = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '50',
                    'profit_split_hari_pct' => '50',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $ag2 = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '50',
                    'profit_split_hari_pct' => '50',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $alloc = AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $ag1->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 20,
            'area_uom' => 'ACRE',
            'starts_on' => '2024-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->postJson('/api/projects', [
            'name' => 'Bad link',
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'agreement_id' => $ag2->id,
            'agreement_allocation_id' => $alloc->id,
            'status' => 'ACTIVE',
        ])->assertStatus(422);
    }

    public function test_project_update_rejects_linking_agreement_without_resolvable_settlement(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'Upd',
            'status' => 'ACTIVE',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $b['cycle']->id,
            'party_id' => $b['landlord']->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $this->withHeaders($this->apiHeaders($tenant))->putJson('/api/projects/' . $project->id, [
            'agreement_id' => $ag->id,
        ])->assertStatus(422);
    }

    public function test_legacy_project_rule_only_resolves_and_project_show_includes_settlement_resolution(): void
    {
        $b = $this->seedTenantBasics();
        $tenant = $b['tenant'];
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $b['hari']->id,
            'crop_cycle_id' => $b['cycle']->id,
            'name' => 'Legacy',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 33.00,
            'profit_split_hari_pct' => 67.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $rule = app(ProjectSettlementRuleResolver::class)->resolveSettlementRule($project);
        $this->assertSame('project_rule', $rule['resolution_source']);

        $show = $this->withHeaders($this->apiHeaders($tenant))
            ->getJson('/api/projects/' . $project->id)
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('settlement_resolution', $show);
        $this->assertSame('project_rule', $show['settlement_resolution']['resolution_source']);

        $rules = $this->withHeaders($this->apiHeaders($tenant))
            ->getJson('/api/projects/' . $project->id . '/rules')
            ->assertOk()
            ->json();

        $this->assertSame('project_rule', $rules['_meta']['settlement_terms_primary']);
    }
}
