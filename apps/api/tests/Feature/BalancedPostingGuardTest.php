<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Account;
use App\Models\CropCycle;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class BalancedPostingGuardTest extends TestCase
{
    use DatabaseMigrations;

    private Tenant $tenant;
    private Account $cashAccount;
    private CropCycle $cropCycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Balance Guard Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);
        $this->cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();
        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Balance Guard Cycle',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
    }

    /** @test */
    public function rejects_unbalanced_at_commit(): void
    {
        $now = now();
        $pgId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        DB::beginTransaction();

        DB::table('posting_groups')->insert([
            'id' => $pgId,
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $sourceId,
            'posting_date' => '2024-01-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ledger_entries')->insert([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgId,
            'account_id' => $this->cashAccount->id,
            'debit_amount' => 100,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::commit();
            $this->fail('Expected exception from deferred balance trigger');
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->assertStringContainsString('balanced', strtolower($e->getMessage()));
        }
    }

    /** @test */
    public function accepts_balanced_in_transaction(): void
    {
        $now = now();
        $pgId = (string) Str::uuid();
        $sourceId = (string) Str::uuid();

        DB::beginTransaction();

        DB::table('posting_groups')->insert([
            'id' => $pgId,
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $sourceId,
            'posting_date' => '2024-01-15',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ledger_entries')->insert([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgId,
            'account_id' => $this->cashAccount->id,
            'debit_amount' => 100,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('ledger_entries')->insert([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pgId,
            'account_id' => $this->cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => 100,
            'currency_code' => 'GBP',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::commit();

        $this->assertSame(2, (int) DB::table('ledger_entries')->where('posting_group_id', $pgId)->count());

        // Clear committed data so DatabaseMigrations' migrate:rollback in beforeApplicationDestroyed
        // can run (some migration down()s assume empty/rewritable tables). TRUNCATE bypasses
        // the immutability triggers (they fire on DELETE, not TRUNCATE). CASCADE clears
        // dependent tables (ledger_entries, allocation_rows, settlements, etc.) that reference posting_groups.
        DB::statement('TRUNCATE posting_groups CASCADE');
    }
}
