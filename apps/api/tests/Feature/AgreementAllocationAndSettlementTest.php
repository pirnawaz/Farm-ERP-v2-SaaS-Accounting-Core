<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Tenant;
use App\Services\AgreementAllocationCapacityService;
use App\Services\ProjectSettlementRuleResolver;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgreementAllocationAndSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_adds_agreement_allocations_and_project_columns(): void
    {
        $this->assertTrue(Schema::hasTable('agreement_allocations'));
        $this->assertTrue(Schema::hasColumns('projects', ['agreement_id', 'agreement_allocation_id']));
    }

    public function test_over_allocation_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        (new ModulesSeeder)->run();
        SystemAccountsSeeder::runForTenant($tenant->id);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'P1',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $landlord = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'L1',
            'party_types' => ['LANDLORD'],
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $cycle->id,
            'party_id' => $landlord->id,
            'terms' => ['settlement' => [
                'profit_split_landlord_pct' => '50',
                'profit_split_hari_pct' => '50',
                'kamdari_pct' => '0',
            ]],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $ag->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 60,
            'area_uom' => 'ACRE',
            'starts_on' => '2024-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $svc = app(AgreementAllocationCapacityService::class);

        $this->expectException(\InvalidArgumentException::class);
        $svc->assertWithinParcelCapacity(
            $tenant->id,
            $parcel->id,
            '50',
            '2024-06-01',
            null,
            AgreementAllocation::STATUS_ACTIVE
        );
    }

    public function test_ended_allocation_does_not_block_new_overlap(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'status' => 'active']);
        (new ModulesSeeder)->run();
        SystemAccountsSeeder::runForTenant($tenant->id);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'P2',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $landlord = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'L2',
            'party_types' => ['LANDLORD'],
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'OPEN',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => null,
            'crop_cycle_id' => $cycle->id,
            'party_id' => $landlord->id,
            'terms' => [],
            'effective_from' => '2025-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $ag->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 100,
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-06-30',
            'status' => AgreementAllocation::STATUS_ENDED,
        ]);

        $svc = app(AgreementAllocationCapacityService::class);
        $svc->assertWithinParcelCapacity(
            $tenant->id,
            $parcel->id,
            '100',
            '2025-07-01',
            null,
            AgreementAllocation::STATUS_ACTIVE
        );

        $this->assertTrue(true);
    }

    public function test_settlement_prefers_agreement_terms_when_linked(): void
    {
        $tenant = Tenant::create(['name' => 'T3', 'status' => 'active']);
        (new ModulesSeeder)->run();
        SystemAccountsSeeder::runForTenant($tenant->id);

        $hari = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'H',
            'party_types' => ['HARI'],
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
            'party_id' => $hari->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Proj',
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
            'crop_cycle_id' => $cycle->id,
            'party_id' => Party::create(['tenant_id' => $tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']])->id,
            'terms' => [
                'settlement' => [
                    'profit_split_landlord_pct' => '70',
                    'profit_split_hari_pct' => '30',
                    'kamdari_pct' => '0',
                ],
            ],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $project->update(['agreement_id' => $ag->id]);

        $resolver = app(ProjectSettlementRuleResolver::class);
        $rule = $resolver->resolveSettlementRule($project->fresh());
        $this->assertSame('agreement', $rule['resolution_source']);
        $this->assertEquals(70.0, (float) $rule['profit_split_landlord_pct']);
        $this->assertEquals(30.0, (float) $rule['profit_split_hari_pct']);
    }

    public function test_backfill_command_is_idempotent(): void
    {
        $tenant = Tenant::create(['name' => 'T4', 'status' => 'active']);
        (new ModulesSeeder)->run();
        SystemAccountsSeeder::runForTenant($tenant->id);

        $hari = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'H4',
            'party_types' => ['HARI'],
        ]);

        $landlordP = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'L4',
            'party_types' => ['LANDLORD'],
        ]);

        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel',
            'total_acres' => 50,
            'notes' => null,
        ]);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'C4',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $hari->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Backfill Proj',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $project->id,
            'profit_split_landlord_pct' => 55.00,
            'profit_split_hari_pct' => 45.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);

        $ag = Agreement::create([
            'tenant_id' => $tenant->id,
            'agreement_type' => Agreement::TYPE_LAND_LEASE,
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'party_id' => $landlordP->id,
            'terms' => [],
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $la = \App\Models\LandAllocation::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => $hari->id,
            'allocated_acres' => 12.5,
        ]);

        $project->update(['land_allocation_id' => $la->id]);

        Artisan::call('agreements:backfill-allocations');
        $c1 = AgreementAllocation::where('backfilled_for_project_id', $project->id)->count();
        $this->assertSame(1, $c1);

        Artisan::call('agreements:backfill-allocations');
        $c2 = AgreementAllocation::where('backfilled_for_project_id', $project->id)->count();
        $this->assertSame(1, $c2);

        $ag->refresh();
        $this->assertArrayHasKey('settlement', $ag->terms ?? []);
    }
}
