<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\OperationalTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalTransactionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_read_other_tenant_records(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        $cycle1 = CropCycle::create(['tenant_id' => $tenant1->id, 'name' => 'C1', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $cycle2 = CropCycle::create(['tenant_id' => $tenant2->id, 'name' => 'C2', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $party1 = Party::create(['tenant_id' => $tenant1->id, 'name' => 'P1', 'party_types' => ['HARI']]);
        $party2 = Party::create(['tenant_id' => $tenant2->id, 'name' => 'P2', 'party_types' => ['HARI']]);

        $project1 = Project::create(['tenant_id' => $tenant1->id, 'party_id' => $party1->id, 'crop_cycle_id' => $cycle1->id, 'name' => 'Project 1', 'status' => 'ACTIVE']);
        $project2 = Project::create(['tenant_id' => $tenant2->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cycle2->id, 'name' => 'Project 2', 'status' => 'ACTIVE']);

        $t1 = OperationalTransaction::create([
            'tenant_id' => $tenant1->id,
            'project_id' => $project1->id,
            'crop_cycle_id' => $cycle1->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $t2 = OperationalTransaction::create([
            'tenant_id' => $tenant2->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cycle2->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 200.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson('/api/operational-transactions');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals($t1->id, $data[0]['id']);
    }

    public function test_cannot_access_other_tenant_transaction_by_id(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        $cycle1 = CropCycle::create(['tenant_id' => $tenant1->id, 'name' => 'C1', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $cycle2 = CropCycle::create(['tenant_id' => $tenant2->id, 'name' => 'C2', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $party1 = Party::create(['tenant_id' => $tenant1->id, 'name' => 'P1', 'party_types' => ['HARI']]);
        $party2 = Party::create(['tenant_id' => $tenant2->id, 'name' => 'P2', 'party_types' => ['HARI']]);

        $project1 = Project::create(['tenant_id' => $tenant1->id, 'party_id' => $party1->id, 'crop_cycle_id' => $cycle1->id, 'name' => 'Project 1', 'status' => 'ACTIVE']);
        $project2 = Project::create(['tenant_id' => $tenant2->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cycle2->id, 'name' => 'Project 2', 'status' => 'ACTIVE']);

        $t2 = OperationalTransaction::create([
            'tenant_id' => $tenant2->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cycle2->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/operational-transactions/{$t2->id}");

        $response->assertStatus(404);
    }

    public function test_cannot_update_other_tenant_transaction(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        $cycle2 = CropCycle::create(['tenant_id' => $tenant2->id, 'name' => 'C2', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $party2 = Party::create(['tenant_id' => $tenant2->id, 'name' => 'P2', 'party_types' => ['HARI']]);
        $project2 = Project::create(['tenant_id' => $tenant2->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cycle2->id, 'name' => 'Project 2', 'status' => 'ACTIVE']);

        $t2 = OperationalTransaction::create([
            'tenant_id' => $tenant2->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cycle2->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->putJson("/api/operational-transactions/{$t2->id}", ['amount' => 999.00]);

        $response->assertStatus(404);
    }

    public function test_cannot_delete_other_tenant_transaction(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1', 'status' => 'active']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2', 'status' => 'active']);
        $cycle2 = CropCycle::create(['tenant_id' => $tenant2->id, 'name' => 'C2', 'start_date' => '2024-01-01', 'end_date' => '2024-12-31', 'status' => 'OPEN']);
        $party2 = Party::create(['tenant_id' => $tenant2->id, 'name' => 'P2', 'party_types' => ['HARI']]);
        $project2 = Project::create(['tenant_id' => $tenant2->id, 'party_id' => $party2->id, 'crop_cycle_id' => $cycle2->id, 'name' => 'Project 2', 'status' => 'ACTIVE']);

        $t2 = OperationalTransaction::create([
            'tenant_id' => $tenant2->id,
            'project_id' => $project2->id,
            'crop_cycle_id' => $cycle2->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->deleteJson("/api/operational-transactions/{$t2->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('operational_transactions', ['id' => $t2->id]);
    }
}
