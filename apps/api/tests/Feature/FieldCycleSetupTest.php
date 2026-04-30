<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\LandAllocation;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldCycleSetupTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function makeCycle(Tenant $tenant, string $name = 'Cycle 2026'): CropCycle
    {
        $cropItem = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'custom_name' => 'Wheat',
            'display_name' => 'Wheat',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'tenant_crop_item_id' => $cropItem->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
    }

    public function test_creates_field_cycle_with_cycle_parcel_area_only(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel A',
            'total_acres' => 100,
            'notes' => null,
        ]);

        $payload = [
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'allocated_acres' => 10,
            'project_name' => 'FC A',
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/projects/field-cycle-setup', $payload);

        $res->assertStatus(201);
        $res->assertJsonPath('name', 'FC A');
        $res->assertJsonPath('crop_cycle.id', $cycle->id);

        $projectId = $res->json('id');
        $project = Project::where('tenant_id', $tenant->id)->where('id', $projectId)->firstOrFail();
        $this->assertNotNull($project->land_allocation_id);

        $allocation = LandAllocation::where('tenant_id', $tenant->id)->where('id', $project->land_allocation_id)->firstOrFail();
        $this->assertSame($cycle->id, $allocation->crop_cycle_id);
        $this->assertSame($parcel->id, $allocation->land_parcel_id);
        $this->assertNull($allocation->party_id);
        $this->assertEquals(10.0, (float) $allocation->allocated_acres);

        $party = Party::where('tenant_id', $tenant->id)->where('id', $project->party_id)->firstOrFail();
        $this->assertSame('Landlord', $party->name);
        $this->assertContains('LANDLORD', $party->party_types ?? []);
    }

    public function test_creates_field_cycle_with_agreement_and_agreement_allocation(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel B',
            'total_acres' => 50,
            'notes' => null,
        ]);

        $landlord = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'LL',
            'party_types' => ['LANDLORD'],
        ]);

        $agreement = Agreement::create([
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
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $agreementAlloc = AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $agreement->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 12,
            'area_uom' => 'ACRE',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $payload = [
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'allocated_acres' => 12,
            'project_name' => 'FC B',
            'agreement_id' => $agreement->id,
            'agreement_allocation_id' => $agreementAlloc->id,
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/projects/field-cycle-setup', $payload);

        $res->assertStatus(201);
        $res->assertJsonPath('agreement_id', $agreement->id);
        $res->assertJsonPath('agreement_allocation_id', $agreementAlloc->id);
    }

    public function test_validation_fails_when_agreement_allocation_not_in_selected_agreement(): void
    {
        $tenant = Tenant::create(['name' => 'T3', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel C',
            'total_acres' => 50,
            'notes' => null,
        ]);

        $landlord = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'LL3',
            'party_types' => ['LANDLORD'],
        ]);

        $agreement1 = Agreement::create([
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
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $agreement2 = Agreement::create([
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
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'priority' => 1,
            'status' => Agreement::STATUS_ACTIVE,
        ]);

        $agreementAlloc = AgreementAllocation::create([
            'tenant_id' => $tenant->id,
            'agreement_id' => $agreement2->id,
            'land_parcel_id' => $parcel->id,
            'allocated_area' => 10,
            'area_uom' => 'ACRE',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $payload = [
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'allocated_acres' => 10,
            'project_name' => 'FC C',
            'agreement_id' => $agreement1->id,
            'agreement_allocation_id' => $agreementAlloc->id,
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/projects/field-cycle-setup', $payload);

        $res->assertStatus(422);
    }

    public function test_validation_fails_for_cross_tenant_mismatch(): void
    {
        $tenant = Tenant::create(['name' => 'T4', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'T4-other', 'status' => 'active']);

        $cycle = $this->makeCycle($tenant);
        $otherParcel = LandParcel::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other parcel',
            'total_acres' => 20,
            'notes' => null,
        ]);

        $payload = [
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $otherParcel->id,
            'allocated_acres' => 5,
            'project_name' => 'FC D',
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/projects/field-cycle-setup', $payload);

        $res->assertStatus(422);
        $this->assertArrayHasKey('land_parcel_id', $res->json('errors') ?? []);
    }

    public function test_completion_path_updates_missing_links_idempotently(): void
    {
        $tenant = Tenant::create(['name' => 'T5', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel E',
            'total_acres' => 80,
            'notes' => null,
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => null,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Incomplete',
            'status' => 'ACTIVE',
        ]);

        $payload = [
            'project_id' => $project->id,
            'crop_cycle_id' => $cycle->id,
            'land_parcel_id' => $parcel->id,
            'allocated_acres' => 15,
            'project_name' => 'Completed',
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson('/api/projects/field-cycle-setup', $payload);

        $res->assertStatus(201);
        $res->assertJsonPath('id', $project->id);

        $project->refresh();
        $this->assertSame('Completed', $project->name);
        $this->assertNotNull($project->party_id);
        $this->assertNotNull($project->land_allocation_id);
    }
}

