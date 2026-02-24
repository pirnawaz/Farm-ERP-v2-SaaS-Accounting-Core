<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\CropCatalogItem;
use App\Models\TenantCropItem;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\LandAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandAllocationTest extends TestCase
{
    use RefreshDatabase;

    private function createCropCycleWithCrop(Tenant $tenant, string $name = 'Cycle 1'): CropCycle
    {
        $catalog = CropCatalogItem::first();
        $this->assertNotNull($catalog, 'Crop catalog should be seeded');
        $tenantCropItem = TenantCropItem::create([
            'tenant_id' => $tenant->id,
            'crop_catalog_item_id' => $catalog->id,
            'display_name' => $catalog->default_name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        return CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'tenant_crop_item_id' => $tenantCropItem->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
    }

    public function test_store_returns_422_when_crop_cycle_has_no_crop_assigned(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle No Crop',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocated_acres' => 10.00,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The selected crop cycle must have a crop assigned before creating allocations.');
    }

    public function test_update_returns_422_when_target_crop_cycle_has_no_crop_assigned(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cycleWithCrop = $this->createCropCycleWithCrop($tenant, 'Cycle With Crop');
        $cycleNoCrop = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle No Crop',
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
            'status' => 'OPEN',
        ]);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);
        $allocation = LandAllocation::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycleWithCrop->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => null,
            'allocated_acres' => 50.00,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->patchJson("/api/land-allocations/{$allocation->id}", [
                'crop_cycle_id' => $cycleNoCrop->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The selected crop cycle must have a crop assigned before creating allocations.');
    }

    public function test_allocated_acres_cannot_exceed_total_acres(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        // Allocate 60 acres
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocated_acres' => 60.00,
            ]);

        $response->assertStatus(201);

        // Try to allocate 50 more acres (would exceed 100) — returns 422 with message
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'available_acres']);
        $this->assertStringContainsString('exceed', $response->json('message'));
    }

    public function test_update_allocation_validates_total_acres(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $allocation = LandAllocation::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cropCycle->id,
            'land_parcel_id' => $parcel->id,
            'party_id' => $party->id,
            'allocated_acres' => 50.00,
        ]);

        // Try to update to exceed total — returns 422 with message
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->patchJson("/api/land-allocations/{$allocation->id}", [
                'allocated_acres' => 150.00,
            ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'available_acres']);
        $this->assertStringContainsString('exceed', $response->json('message'));
    }

    public function test_cannot_create_allocation_on_closed_crop_cycle(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Closed Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
        ]);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocated_acres' => 10.00,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Allocations can only be created or changed for open crop cycles.');
    }

    public function test_can_create_owner_operated_allocation(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);

        // Create owner-operated allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocation_mode' => 'OWNER',
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertNull($data['party_id']);
        $this->assertEquals('OWNER', $data['allocation_mode']);
    }

    public function test_cannot_create_hari_allocation_without_party(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);

        // Try to create HARI allocation without party_id
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocation_mode' => 'HARI',
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('errors', $response->json());
    }

    public function test_can_create_hari_allocation_with_party(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        // Create HARI allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocation_mode' => 'HARI',
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals($party->id, $data['party_id']);
        $this->assertEquals('HARI', $data['allocation_mode']);
    }

    public function test_unique_constraint_prevents_duplicate_owner_allocations(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);

        // Create first owner-operated allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocation_mode' => 'OWNER',
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(201);

        // Try to create duplicate owner-operated allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => null,
                'allocation_mode' => 'OWNER',
                'allocated_acres' => 30.00,
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('duplicate', strtolower($response->getContent()));
    }

    public function test_unique_constraint_prevents_duplicate_hari_allocations(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = $this->createCropCycleWithCrop($tenant);
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 100.00,
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        // Create first HARI allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocation_mode' => 'HARI',
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(201);

        // Try to create duplicate HARI allocation
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocation_mode' => 'HARI',
                'allocated_acres' => 30.00,
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('duplicate', strtolower($response->getContent()));
    }
}
