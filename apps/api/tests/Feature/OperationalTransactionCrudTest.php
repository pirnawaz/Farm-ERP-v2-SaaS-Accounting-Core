<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\OperationalTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class OperationalTransactionCrudTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
    }

    private function headers(): array
    {
        return [
            'X-Tenant-Id' => $this->tenant->id,
            'X-User-Role' => 'accountant',
        ];
    }

    public function test_can_create_draft_entry(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/operational-transactions', [
                'project_id' => $this->project->id,
                'crop_cycle_id' => $this->project->crop_cycle_id,
                'type' => 'EXPENSE',
                'transaction_date' => '2024-01-15',
                'amount' => 100.50,
                'classification' => 'SHARED',
            ]);

        $response->assertStatus(201);
        $data = $response->json();
        $this->assertEquals('DRAFT', $data['status']);
        $this->assertEquals('EXPENSE', $data['type']);
        $this->assertEquals('100.50', $data['amount']);
    }

    public function test_can_update_draft_entry(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->project->crop_cycle_id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeaders($this->headers())
            ->putJson("/api/operational-transactions/{$entry->id}", [
                'amount' => 150.00,
                'classification' => 'HARI_ONLY',
            ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('150.00', $data['amount']);
        $this->assertEquals('HARI_ONLY', $data['classification']);
    }

    public function test_can_delete_draft_entry(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->project->crop_cycle_id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeaders($this->headers())
            ->deleteJson("/api/operational-transactions/{$entry->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('operational_transactions', ['id' => $entry->id]);
    }

    public function test_validation_fails_for_invalid_data(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/operational-transactions', [
                'project_id' => $this->project->id,
                'crop_cycle_id' => $this->project->crop_cycle_id,
                'type' => 'INVALID',
                'transaction_date' => 'not-a-date',
                'amount' => -100,
                'classification' => 'SHARED',
            ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('type', $errors);
        $this->assertArrayHasKey('transaction_date', $errors);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function test_can_post_draft_via_api(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->project->crop_cycle_id,
            'type' => 'INCOME',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 500.00,
            'classification' => 'SHARED',
        ]);

        $response = $this->withHeaders($this->headers())
            ->postJson("/api/operational-transactions/{$entry->id}/post", [
                'posting_date' => '2024-01-15',
                'idempotency_key' => 'post-test-1',
            ]);

        $response->assertStatus(201);
        $entry->refresh();
        $this->assertEquals('POSTED', $entry->status);
    }
}
