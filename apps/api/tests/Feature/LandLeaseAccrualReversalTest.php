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

class LandLeaseAccrualReversalTest extends TestCase
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

    private function createPostedAccrual(Tenant $tenant, float $amount = 100.00): array
    {
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

        $user = User::where('tenant_id', $tenant->id)->first();
        $postResp = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => '2024-06-15']);
        $postResp->assertStatus(200);
        $accrual->refresh();
        return [$accrual, $landlordParty];
    }

    public function test_reverse_posted_accrual_creates_reversal_posting_group_and_negates_ledger(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$accrual, $landlordParty] = $this->createPostedAccrual($tenant, 150.00);
        $originalPgId = $accrual->posting_group_id;
        $this->assertNotNull($originalPgId);

        $originalDebit = LedgerEntry::where('posting_group_id', $originalPgId)->sum('debit_amount');
        $originalCredit = LedgerEntry::where('posting_group_id', $originalPgId)->sum('credit_amount');
        $this->assertGreaterThan(0, $originalDebit);
        $this->assertGreaterThan(0, $originalCredit);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/reverse", [
                'posting_date' => '2024-07-01',
                'reason' => 'Duplicate accrual',
            ]);

        $response->assertStatus(200);
        $accrual->refresh();
        $this->assertNotNull($accrual->reversal_posting_group_id);
        $this->assertNotNull($accrual->reversed_at);
        $this->assertEquals($user->id, $accrual->reversed_by);
        $this->assertEquals('Duplicate accrual', $accrual->reversal_reason);

        $reversalPgId = $response->json('reversal_posting_group_id');
        $reversalPg = PostingGroup::where('id', $reversalPgId)->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($reversalPg);
        $this->assertEquals('REVERSAL', $reversalPg->source_type);
        $this->assertEquals($originalPgId, $reversalPg->source_id);
        $this->assertEquals($originalPgId, $reversalPg->reversal_of_posting_group_id);

        $reversalDebit = LedgerEntry::where('posting_group_id', $reversalPgId)->sum('debit_amount');
        $reversalCredit = LedgerEntry::where('posting_group_id', $reversalPgId)->sum('credit_amount');
        $this->assertEqualsWithDelta($originalDebit, $reversalCredit, 0.01);
        $this->assertEqualsWithDelta($originalCredit, $reversalDebit, 0.01);

        $originalPg = PostingGroup::find($originalPgId);
        $this->assertNotNull($originalPg);
        $this->assertEquals('LAND_LEASE_ACCRUAL', $originalPg->source_type);
    }

    public function test_second_reverse_returns_422_and_no_duplicate_posting_group(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableLandLeases($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$accrual, $landlordParty] = $this->createPostedAccrual($tenant, 80.00);

        $r1 = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/reverse", [
                'posting_date' => '2024-07-01',
                'reason' => 'First reversal',
            ]);
        $r1->assertStatus(200);
        $reversalPgId = $r1->json('reversal_posting_group_id');
        $countBefore = PostingGroup::where('tenant_id', $tenant->id)->where('reversal_of_posting_group_id', $accrual->posting_group_id)->count();
        $this->assertEquals(1, $countBefore);

        $r2 = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/reverse", [
                'posting_date' => '2024-07-02',
                'reason' => 'Second attempt',
            ]);
        $r2->assertStatus(422);
        $r2->assertJsonFragment(['message' => 'This accrual has already been reversed.']);

        $countAfter = PostingGroup::where('tenant_id', $tenant->id)->where('reversal_of_posting_group_id', $accrual->posting_group_id)->count();
        $this->assertEquals(1, $countAfter);
    }

    public function test_cannot_reverse_draft_accrual(): void
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
            'rent_amount' => 100,
            'frequency' => 'MONTHLY',
            'notes' => null,
        ]);
        $accrual = LandLeaseAccrual::create([
            'tenant_id' => $tenant->id,
            'lease_id' => $lease->id,
            'project_id' => $project->id,
            'period_start' => '2024-06-01',
            'period_end' => '2024-06-30',
            'amount' => 100,
            'memo' => 'June rent',
            'status' => LandLeaseAccrual::STATUS_DRAFT,
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/reverse", [
                'posting_date' => '2024-07-01',
            ]);

        $response->assertStatus(403);
        $accrual->refresh();
        $this->assertNull($accrual->reversal_posting_group_id);
    }

    public function test_tenant_isolation_cannot_reverse_other_tenant_accrual(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenantA = Tenant::create(['name' => 'A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenantA->id);
        SystemAccountsSeeder::runForTenant($tenantB->id);
        $this->enableLandLeases($tenantA);
        $this->enableLandLeases($tenantB);

        $userA = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Admin A',
            'email' => 'admin-a@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);
        $userB = User::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Admin B',
            'email' => 'admin-b@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        [$accrualA, $_] = $this->createPostedAccrual($tenantA, 200.00);

        $response = $this->actingAs($userB)
            ->withHeader('X-Tenant-Id', $tenantB->id)
            ->postJson("/api/land-lease-accruals/{$accrualA->id}/reverse", [
                'posting_date' => '2024-07-01',
            ]);

        $response->assertStatus(404);
        $accrualA->refresh();
        $this->assertNull($accrualA->reversal_posting_group_id);
    }
}
