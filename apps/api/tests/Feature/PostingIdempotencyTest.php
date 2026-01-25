<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Project;
use App\Models\Party;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class PostingIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_with_same_idempotency_key_returns_existing_group(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $transaction = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $idempotencyKey = 'test-key-123';

        // First post
        $response1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$transaction->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $idempotencyKey,
            ]);

        $response1->assertStatus(201);
        $postingGroupId1 = $response1->json('id');

        // Second post with same key
        $response2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$transaction->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => $idempotencyKey,
            ]);

        $response2->assertStatus(201);
        $postingGroupId2 = $response2->json('id');

        // Should return the same posting group
        $this->assertEquals($postingGroupId1, $postingGroupId2);

        // Verify only one posting group exists
        $count = PostingGroup::where('tenant_id', $tenant->id)
            ->where('idempotency_key', $idempotencyKey)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_posting_blocked_when_crop_cycle_closed(): void
    {
        $tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED', // Closed cycle
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);

        $transaction = OperationalTransaction::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $cropCycle->id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => 1000.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/operational-transactions/{$transaction->id}/post", [
                'posting_date' => '2024-06-15',
                'idempotency_key' => 'test-key',
            ]);

        $response->assertStatus(500);
        $this->assertStringContainsString('closed', strtolower($response->getContent()));
    }
}
