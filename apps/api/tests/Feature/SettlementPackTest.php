<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Module;
use App\Models\TenantModule;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Project;
use App\Models\PostingGroup;
use App\Models\AllocationRow;
use App\Models\SettlementPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\ModulesSeeder;

class SettlementPackTest extends TestCase
{
    use RefreshDatabase;

    private function enableSettlements(Tenant $tenant): void
    {
        $m = Module::where('key', 'settlements')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    /** @return array{tenant: Tenant, project: Project, postingGroup: PostingGroup} */
    private function createTenantWithProjectAndAllocation(): array
    {
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'Pack Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant);

        $cycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => '2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $cycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $pg = PostingGroup::create([
            'tenant_id' => $tenant->id,
            'crop_cycle_id' => $cycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-01',
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'POOL_SHARE',
            'amount' => 150.50,
        ]);
        AllocationRow::create([
            'tenant_id' => $tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $project->id,
            'party_id' => $party->id,
            'allocation_type' => 'HARI_ONLY',
            'amount' => 49.50,
        ]);

        return ['tenant' => $tenant, 'project' => $project, 'postingGroup' => $pg];
    }

    public function test_generating_pack_returns_expected_shape_and_totals(): void
    {
        $data = $this->createTenantWithProjectAndAllocation();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $response = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);

        $response->assertStatus(201);
        $body = $response->json();

        $this->assertArrayHasKey('id', $body);
        $this->assertNotEmpty($body['id']);
        $this->assertEquals($tenant->id, $body['tenant_id']);
        $this->assertEquals($project->id, $body['project_id']);
        $this->assertEquals('DRAFT', $body['status']);
        $this->assertArrayHasKey('register_version', $body);
        $this->assertArrayHasKey('summary_json', $body);
        $this->assertArrayHasKey('register_row_count', $body);

        $summary = $body['summary_json'];
        $this->assertArrayHasKey('total_amount', $summary);
        $this->assertArrayHasKey('row_count', $summary);
        $this->assertArrayHasKey('by_allocation_type', $summary);

        $total = (float) $summary['total_amount'];
        $this->assertEqualsWithDelta(200.00, $total, 0.01);
        $this->assertSame(2, $summary['row_count']);
        $this->assertEquals(2, $body['register_row_count']);

        $byType = $summary['by_allocation_type'];
        $this->assertEqualsWithDelta(150.50, (float) ($byType['POOL_SHARE'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(49.50, (float) ($byType['HARI_ONLY'] ?? 0), 0.01);
    }

    public function test_get_pack_returns_pack_and_register_rows(): void
    {
        $data = $this->createTenantWithProjectAndAllocation();
        $tenant = $data['tenant'];
        $project = $data['project'];

        $createResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", []);

        $createResponse->assertStatus(201);
        $packId = $createResponse->json('id');

        $getResponse = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}");

        $getResponse->assertStatus(200);
        $body = $getResponse->json();

        $this->assertEquals($packId, $body['id']);
        $this->assertArrayHasKey('register_rows', $body);
        $rows = $body['register_rows'];
        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('posting_group_id', $rows[0]);
        $this->assertArrayHasKey('posting_date', $rows[0]);
        $this->assertArrayHasKey('source_type', $rows[0]);
        $this->assertArrayHasKey('allocation_type', $rows[0]);
        $this->assertArrayHasKey('amount', $rows[0]);
    }

    public function test_cannot_access_another_tenant_pack(): void
    {
        $data = $this->createTenantWithProjectAndAllocation();
        $tenant1 = $data['tenant'];
        $project1 = $data['project'];

        $createResponse = $this->withHeader('X-Tenant-Id', $tenant1->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project1->id}/settlement-pack", []);

        $createResponse->assertStatus(201);
        $packId = $createResponse->json('id');

        $tenant2 = Tenant::create(['name' => 'Other Tenant', 'status' => 'active']);
        $this->enableSettlements($tenant2);

        $getResponse = $this->withHeader('X-Tenant-Id', $tenant2->id)
            ->withHeader('X-User-Role', 'accountant')
            ->getJson("/api/settlement-packs/{$packId}");

        $getResponse->assertStatus(404);
    }

    public function test_generate_is_idempotent_per_project_and_version(): void
    {
        $data = $this->createTenantWithProjectAndAllocation();
        $tenant = $data['tenant'];
        $project = $data['project'];
        $version = 'test-version-1';

        $r1 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", ['register_version' => $version]);

        $r1->assertStatus(201);
        $id1 = $r1->json('id');

        $r2 = $this->withHeader('X-Tenant-Id', $tenant->id)
            ->withHeader('X-User-Role', 'accountant')
            ->postJson("/api/projects/{$project->id}/settlement-pack", ['register_version' => $version]);

        $r2->assertStatus(201);
        $id2 = $r2->json('id');

        $this->assertSame($id1, $id2);
        $this->assertEquals(1, SettlementPack::where('tenant_id', $tenant->id)->where('project_id', $project->id)->where('register_version', $version)->count());
    }
}
