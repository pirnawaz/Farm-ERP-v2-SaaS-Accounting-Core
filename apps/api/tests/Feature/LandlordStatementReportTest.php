<?php

namespace Tests\Feature;

use App\Domains\Operations\LandLease\LandLease;
use App\Domains\Operations\LandLease\LandLeaseAccrual;
use App\Models\CropCycle;
use App\Models\LandParcel;
use App\Models\Module;
use App\Models\Party;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\User;
use App\Services\TenantContext;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandlordStatementReportTest extends TestCase
{
    use RefreshDatabase;

    private function enableModules(Tenant $tenant, array $keys = ['reports', 'land_leases']): void
    {
        foreach ($keys as $key) {
            $m = Module::where('key', $key)->first();
            if ($m) {
                TenantModule::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'module_id' => $m->id],
                    ['status' => 'ENABLED', 'enabled_at' => now(), 'disabled_at' => null, 'enabled_by_user_id' => null]
                );
            }
        }
    }

    private function createPostedAccrualForLandlord(Tenant $tenant, User $user, Party $landlordParty, float $amount, string $postingDate): void
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
        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->postJson("/api/land-lease-accruals/{$accrual->id}/post", ['posting_date' => $postingDate])
            ->assertStatus(200);
    }

    public function test_landlord_statement_returns_opening_lines_closing_ordered_by_posting_date(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant);

        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord One',
            'party_types' => ['LANDLORD'],
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $this->createPostedAccrualForLandlord($tenant, $user, $landlordParty, 100.00, '2024-06-15');
        $this->createPostedAccrualForLandlord($tenant, $user, $landlordParty, 200.00, '2024-06-20');

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/landlord-statement?' . http_build_query([
                'party_id' => $landlordParty->id,
                'date_from' => '2024-06-01',
                'date_to' => '2024-06-30',
            ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals($landlordParty->id, $data['party']['id']);
        $this->assertEquals('Landlord One', $data['party']['name']);
        $this->assertEquals('2024-06-01', $data['date_from']);
        $this->assertEquals('2024-06-30', $data['date_to']);

        $lines = $data['lines'];
        $this->assertGreaterThanOrEqual(2, count($lines));
        $dates = array_column($lines, 'posting_date');
        $sorted = $dates;
        sort($sorted);
        $this->assertEquals($sorted, $dates, 'Lines should be ordered by posting_date asc');

        foreach ($lines as $line) {
            $this->assertArrayHasKey('posting_date', $line);
            $this->assertArrayHasKey('description', $line);
            $this->assertArrayHasKey('debit', $line);
            $this->assertArrayHasKey('credit', $line);
            $this->assertArrayHasKey('running_balance', $line);
            $this->assertArrayHasKey('posting_group_id', $line);
        }

        $this->assertArrayHasKey('opening_balance', $data);
        $this->assertArrayHasKey('closing_balance', $data);
        $lastLine = end($lines);
        $this->assertEqualsWithDelta($data['closing_balance'], $lastLine['running_balance'], 0.01);
    }

    public function test_landlord_statement_opening_balance_excludes_period(): void
    {
        TenantContext::clear();
        (new ModulesSeeder)->run();
        $tenant = Tenant::create(['name' => 'T', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($tenant->id);
        $this->enableModules($tenant);

        $landlordParty = Party::create([
            'tenant_id' => $tenant->id,
            'name' => 'Landlord Two',
            'party_types' => ['LANDLORD'],
        ]);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role' => 'tenant_admin',
            'is_enabled' => true,
        ]);

        $this->createPostedAccrualForLandlord($tenant, $user, $landlordParty, 50.00, '2024-05-10');
        $this->createPostedAccrualForLandlord($tenant, $user, $landlordParty, 75.00, '2024-06-15');

        $response = $this->actingAs($user)
            ->withHeader('X-Tenant-Id', $tenant->id)
            ->getJson('/api/reports/landlord-statement?' . http_build_query([
                'party_id' => $landlordParty->id,
                'date_from' => '2024-06-01',
                'date_to' => '2024-06-30',
            ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('opening_balance', $data);
        $this->assertArrayHasKey('lines', $data);
        $linesInPeriod = $data['lines'];
        $opening = (float) $data['opening_balance'];
        $closing = (float) $data['closing_balance'];
        $periodNet = array_reduce($linesInPeriod, function ($sum, $line) {
            return $sum + ((float) $line['debit'] - (float) $line['credit']);
        }, 0);
        $this->assertEqualsWithDelta($closing, $opening + $periodNet, 0.01);
    }
}
