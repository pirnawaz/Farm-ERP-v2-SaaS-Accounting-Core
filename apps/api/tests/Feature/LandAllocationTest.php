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
}
