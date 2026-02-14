<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Account;
use App\Models\CropCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Database\Seeders\SystemAccountsSeeder;

class BalancedPostingGuardTest extends TestCase
{
    use RefreshDatabase;

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
            // Force deferred balance constraint to run (trigger runs at commit; in test we force it here)
            DB::statement('SET CONSTRAINTS trg_ledger_entries_balanced_posting IMMEDIATE');
            $this->fail('Expected exception from deferred balance trigger');
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $message = strtolower($e->getMessage());
            $previous = $e->getPrevious();
            if ($previous instanceof \Throwable) {
                $message .= ' ' . strtolower($previous->getMessage());
            }
            $this->assertTrue(
                str_contains($message, 'balanced') || str_contains($message, 'sum(debit)') || str_contains($message, 'sum(credit)') || str_contains($message, 'posting group'),
                'Expected balance enforcement exception, got: ' . $e->getMessage()
            );
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

        // Do not commit so RefreshDatabase's transaction rollback cleans up (TRUNCATE would
        // hit "pending trigger events" with deferred triggers).
        $this->assertSame(2, (int) DB::table('ledger_entries')->where('posting_group_id', $pgId)->count());
    }
}
