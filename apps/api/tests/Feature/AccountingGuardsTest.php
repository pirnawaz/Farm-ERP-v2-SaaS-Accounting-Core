<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Project;
use App\Models\OperationalTransaction;
use App\Models\CropCycle;
use App\Models\Party;
use App\Models\Account;
use App\Services\PostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class AccountingGuardsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Project $project;
    private CropCycle $cropCycle;
    private Account $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => '2024 Cycle',
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
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        $this->cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();
    }

    /** @test */
    public function immutability_blocks_posting_groups_update(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);
        $postingGroup = $this->app->make(PostingService::class)->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-immut-1'
        );

        $this->expectException(\Throwable::class);
        DB::table('posting_groups')->where('id', $postingGroup->id)->update(['posting_date' => '2024-01-20']);
    }

    /** @test */
    public function immutability_blocks_allocation_rows_delete(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);
        $postingGroup = $this->app->make(PostingService::class)->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-immut-2'
        );

        $this->expectException(\Throwable::class);
        DB::table('allocation_rows')->where('posting_group_id', $postingGroup->id)->delete();
    }

    /** @test */
    public function immutability_blocks_ledger_entries_update(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);
        $postingGroup = $this->app->make(PostingService::class)->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-immut-3'
        );

        $this->expectException(\Throwable::class);
        DB::table('ledger_entries')->where('posting_group_id', $postingGroup->id)->update(['debit_amount' => 1]);
    }

    /** @test */
    public function posting_blocked_when_crop_cycle_closed(): void
    {
        $closedCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Closed Cycle',
            'start_date' => '2023-01-01',
            'end_date' => '2023-12-31',
            'status' => 'CLOSED',
        ]);

        try {
            DB::table('posting_groups')->insert([
                'tenant_id' => $this->tenant->id,
                'crop_cycle_id' => $closedCycle->id,
                'source_type' => 'OPERATIONAL',
                'source_id' => (string) Str::uuid(),
                'posting_date' => '2024-01-15',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected DB to block posting when crop cycle is closed');
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            $this->assertStringContainsString('crop cycle is closed', $msg, 'DB must reject with clear message');
        }
    }

    /** @test */
    public function posting_allowed_when_crop_cycle_open(): void
    {
        $openCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Open Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);

        DB::table('posting_groups')->insert([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $openCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('posting_groups', 1);
    }

    /** @test */
    public function posting_blocked_if_cycle_closed_after_draft(): void
    {
        $openCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Later Closed Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $party = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Draft Party',
            'party_types' => ['HARI'],
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $party->id,
            'crop_cycle_id' => $openCycle->id,
            'name' => 'Draft Project',
            'status' => 'ACTIVE',
        ]);
        $draftTxn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'crop_cycle_id' => $openCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 50.00,
            'classification' => 'SHARED',
        ]);

        $openCycle->update(['status' => 'CLOSED']);

        try {
            $this->app->make(PostingService::class)->postOperationalTransaction(
                $draftTxn->id,
                $this->tenant->id,
                '2024-01-15',
                'idem-closed-after-draft'
            );
            $this->fail('Expected DB to block posting when cycle was closed after draft');
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            $this->assertStringContainsString('crop cycle is closed', $msg, 'DB must reject with clear message');
        }
    }

    /** @test */
    public function idempotency_duplicate_posting_group_fails(): void
    {
        $openCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Open Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $sourceId = (string) Str::uuid();
        $now = now();

        DB::table('posting_groups')->insert([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $openCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $sourceId,
            'posting_date' => '2024-01-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(\Throwable::class);
        DB::table('posting_groups')->insert([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $openCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $sourceId,
            'posting_date' => '2024-01-16',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @test */
    public function immutability_blocks_ledger_entries_delete(): void
    {
        $entry = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-01-15',
            'amount' => 100.00,
            'classification' => 'SHARED',
        ]);
        $postingGroup = $this->app->make(PostingService::class)->postOperationalTransaction(
            $entry->id,
            $this->tenant->id,
            '2024-01-15',
            'idem-immut-delete'
        );

        $ledgerEntryId = $postingGroup->ledgerEntries()->first()->id;

        $this->expectException(\Throwable::class);
        DB::table('ledger_entries')->where('id', $ledgerEntryId)->delete();
    }
}
