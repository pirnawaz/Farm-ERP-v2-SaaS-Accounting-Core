<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Party;
use App\Models\LandAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allocated_acres_cannot_exceed_total_acres(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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

        // Try to allocate 50 more acres (would exceed 100) — LandAllocationService throws
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson('/api/land-allocations', [
                'crop_cycle_id' => $cropCycle->id,
                'land_parcel_id' => $parcel->id,
                'party_id' => $party->id,
                'allocated_acres' => 50.00,
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('exceed', $response->getContent());
    }

    public function test_update_allocation_validates_total_acres(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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

        // Try to update to exceed total — LandAllocationService throws
        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/land-allocations/{$allocation->id}", [
                'allocated_acres' => 150.00,
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('exceed', $response->getContent());
    }

    public function test_can_create_owner_operated_allocation(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
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
