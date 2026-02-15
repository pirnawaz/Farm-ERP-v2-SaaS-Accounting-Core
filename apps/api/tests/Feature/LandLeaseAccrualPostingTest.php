<?php

namespace Tests\Feature;

use App\Domains\Operations\LandLease\LandLease;
use App\Domains\Operations\LandLease\LandLeaseAccrual;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandLeaseAccrualPostingTest extends TestCase
{
    use RefreshDatabase;

    private function enableLandLeases(Tenant $tenant): void
    {
        $m = Module::where('key', 'land_leases')->first();
        if ($m) {
            TenantModule::firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
            );
        }
    }

    private function createLeaseAndAccrual(
        Tenant $tenant,
        CropCycle $cropCycle,
        Project $project,
        Party $landlordParty,
        float $amount = 100.00
    ): array {
        $parcel = LandParcel::create([
            'tenant_id' => $tenant->id,
            'name' => 'Parcel 1',
            'total_acres' => 10,
        ]);
        $lease = LandLease::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'land_parcel_id' => $parcel->id,
            'landlord_party_id' => $landlordParty->id,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'rent_amount' => $amount,
            'frequency' => 'MONTHLY',
            'notes' => null,
        ]);
        $accrual = LandLeaseAccrual::create([
            'tenant_id' => $tenant->id,
            'lease_id' => $lease->id,
            'project_id' => $project->id,
            'period_start' => '2024-06-01',
            'period_end' => '2024-06-30',
            'amount' => $amount,
            'memo' => 'June rent',
            'status' => LandLeaseAccrual::STATUS_DRAFT,
        ]);
        return [$lease, $accrual];
    }

    public function test_posting_draft_accrual_creates_posting_group_and_marks_posted(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$lease, $accrual] = $this->createLeaseAndAccrual($tenant, $cropCycle, $project, $landlordParty, 150.00);
        $postingDate = '2024-06-15';

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", [
                'posting_date' => $postingDate,
            ]);

        $response->assertStatus(200);
        $accrual->refresh();
        $this->assertEquals(LandLeaseAccrual::STATUS_POSTED, $accrual->status);
        $this->assertNotNull($accrual->posting_group_id);
        $this->assertNotNull($accrual->posted_at);
        $this->assertEquals($user->id, $accrual->posted_by);

        $pgId = $response->json('posting_group_id');
        $this->assertNotNull($pgId);
        $pg = PostingGroup::where('id', $pgId)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($pg);
        $this->assertEquals('LAND_LEASE_ACCRUAL', $pg->source_type);
        $this->assertEquals($accrual->id, $pg->source_id);
        $this->assertEquals($postingDate, $pg->posting_date->format('Y-m-d'));

        $row = AllocationRow::where('posting_group_id', $pg->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals('LEASE_RENT', $row->allocation_type);
        $this->assertEquals('LANDLORD_ONLY', $row->allocation_scope);
        $this->assertEquals($project->id, $row->project_id);
        $this->assertEquals($landlordParty->id, $row->party_id);
        $this->assertEqualsWithDelta(150.00, (float) $row->amount, 0.01);

        $debits = LedgerEntry::where('posting_group_id', $pg->id)->sum('debit_amount');
        $credits = LedgerEntry::where('posting_group_id', $pg->id)->sum('credit_amount');
        $this->assertEqualsWithDelta($debits, $credits, 0.01);
    }

    public function test_posting_same_accrual_twice_returns_same_posting_group_idempotent(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$lease, $accrual] = $this->createLeaseAndAccrual($tenant, $cropCycle, $project, $landlordParty, 200.00);
        $postingDate = '2024-06-15';

        $r1 = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => $postingDate]);
        $r1->assertStatus(200);
        $pgId1 = $r1->json('posting_group_id');
        $this->assertNotNull($pgId1);

        $r2 = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => $postingDate]);
        $r2->assertStatus(200);
        $pgId2 = $r2->json('posting_group_id');
        $this->assertEquals($pgId1, $pgId2);

        $pgCount = PostingGroup::where('tenant_id', $tenant->id)
            ->where('source_type', 'LAND_LEASE_ACCRUAL')
            ->where('source_id', $accrual->id)
            ->count();
        $this->assertEquals(1, $pgCount);
    }

    public function test_cannot_post_if_status_already_posted_returns_422(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$lease, $accrual] = $this->createLeaseAndAccrual($tenant, $cropCycle, $project, $landlordParty);
        $accrual->update(['status' => LandLeaseAccrual::STATUS_POSTED]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => '2024-06-15']);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Only DRAFT accruals can be posted.']);
    }

    public function test_cannot_update_or_delete_after_posting_policy_blocks(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$lease, $accrual] = $this->createLeaseAndAccrual($tenant, $cropCycle, $project, $landlordParty);
        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => '2024-06-15'])
            ->assertStatus(200);

        $accrual->refresh();
        $this->assertEquals(LandLeaseAccrual::STATUS_POSTED, $accrual->status);

        $putResp = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->putJson("/api/land-lease-accruals/{$accrual->id}", ['amount' => 999]);
        $putResp->assertStatus(403);

        $delResp = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->deleteJson("/api/land-lease-accruals/{$accrual->id}");
        $delResp->assertStatus(403);
    }

    public function test_posting_blocked_when_crop_cycle_closed(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);

        $cropCycle = CropCycle::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cycle 1',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'CLOSED',
        ]);
        $projectParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Project Party',
            'party_types' => ['LANDLORD'],
        ]);
        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord',
            'party_types' => ['LANDLORD'],
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'party_id' => $projectParty->id,
            'crop_cycle_id' => $cropCycle->id,
            'name' => 'Project 1',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$lease, $accrual] = $this->createLeaseAndAccrual($tenant, $cropCycle, $project, $landlordParty);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => '2024-06-15']);

        $response->assertStatus(422);
        $accrual->refresh();
        $this->assertEquals(LandLeaseAccrual::STATUS_DRAFT, $accrual->status);
        $this->assertNull($accrual->posting_group_id);
    }
}
