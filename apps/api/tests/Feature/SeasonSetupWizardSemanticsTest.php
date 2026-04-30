<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\AgreementAllocation;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantCropItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeasonSetupWizardSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    private function makeCycle(Tenant $tenant): CropCycle
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
            'name' => 'Season 2026',
            'tenant_crop_item_id' => $cropItem->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'OPEN',
        ]);
    }

    public function test_successful_bulk_setup_multiple_parcels(): void
    {
        $tenant = Tenant::create(['name' => 'T1', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel1 = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'total_acres' => 100, 'notes' => null]);
        $parcel2 = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P2', 'total_acres' => 100, 'notes' => null]);

        $payload = [
            'assignments' => [
                [
                    'land_parcel_id' => $parcel1->id,
                    'blocks' => [
                        ['tenant_crop_item_id' => $cycle->tenant_crop_item_id, 'name' => 'A', 'area' => 10],
                    ],
                ],
                [
                    'land_parcel_id' => $parcel2->id,
                    'blocks' => [
                        ['tenant_crop_item_id' => $cycle->tenant_crop_item_id, 'name' => 'B', 'area' => 20],
                    ],
                ],
            ],
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload);

        $res->assertStatus(200);
        $this->assertSame(2, count($res->json('projects') ?? []));
    }

    public function test_bulk_setup_with_agreements_on_some_rows(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel1 = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'total_acres' => 100, 'notes' => null]);
        $parcel2 = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P2', 'total_acres' => 100, 'notes' => null]);

        $landlord = Party::create(['tenant_id' => $tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']]);
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
            'land_parcel_id' => $parcel1->id,
            'allocated_area' => 10,
            'area_uom' => 'ACRE',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'status' => AgreementAllocation::STATUS_ACTIVE,
        ]);

        $payload = [
            'assignments' => [
                [
                    'land_parcel_id' => $parcel1->id,
                    'blocks' => [
                        [
                            'tenant_crop_item_id' => $cycle->tenant_crop_item_id,
                            'name' => 'A',
                            'area' => 10,
                            'agreement_id' => $agreement->id,
                            'agreement_allocation_id' => $agreementAlloc->id,
                        ],
                    ],
                ],
                [
                    'land_parcel_id' => $parcel2->id,
                    'blocks' => [
                        ['tenant_crop_item_id' => $cycle->tenant_crop_item_id, 'name' => 'B', 'area' => 20],
                    ],
                ],
            ],
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload);

        $res->assertStatus(200);
        $this->assertSame(2, count($res->json('projects') ?? []));
    }

    public function test_invalid_agreement_allocation_in_bulk_submission_reports_error(): void
    {
        $tenant = Tenant::create(['name' => 'T3', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'total_acres' => 100, 'notes' => null]);

        $landlord = Party::create(['tenant_id' => $tenant->id, 'name' => 'LL', 'party_types' => ['LANDLORD']]);
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
            'assignments' => [
                [
                    'land_parcel_id' => $parcel->id,
                    'blocks' => [
                        [
                            'tenant_crop_item_id' => $cycle->tenant_crop_item_id,
                            'name' => 'A',
                            'area' => 10,
                            'agreement_id' => $agreement1->id,
                            'agreement_allocation_id' => $agreementAlloc->id,
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->withHeaders($this->headers($tenant))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload);

        $res->assertStatus(200);
        $this->assertSame('error', $res->json('results.0.status'));
    }

    public function test_rerunning_wizard_is_idempotent_for_same_blocks(): void
    {
        $tenant = Tenant::create(['name' => 'T4', 'status' => 'active']);
        $cycle = $this->makeCycle($tenant);
        $parcel = LandParcel::create(['tenant_id' => $tenant->id, 'name' => 'P1', 'total_acres' => 100, 'notes' => null]);

        $payload = [
            'assignments' => [
                [
                    'land_parcel_id' => $parcel->id,
                    'blocks' => [
                        ['tenant_crop_item_id' => $cycle->tenant_crop_item_id, 'name' => 'A', 'area' => 10],
                    ],
                ],
            ],
        ];

        $res1 = $this->withHeaders($this->headers($tenant))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload);
        $res1->assertStatus(200);

        $res2 = $this->withHeaders($this->headers($tenant))
            ->postJson("/api/crop-cycles/{$cycle->id}/season-setup", $payload);
        $res2->assertStatus(200);

        $this->assertSame(
            $res1->json('projects.0.project_id'),
            $res2->json('projects.0.project_id')
        );
    }
}

