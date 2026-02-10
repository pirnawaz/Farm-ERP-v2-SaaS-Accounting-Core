<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AllocationRow;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\OperationalTransaction;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\ReclassCorrection;
use App\Models\Tenant;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassifyLegacyPartyOnlyExpensesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;

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

        $hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);

        ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 50.00,
            'profit_split_hari_pct' => 50.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
    }

    /**
     * Create legacy-style data: OPERATIONAL expense with classification HARI_ONLY/LANDLORD_ONLY
     * but AllocationRow has allocation_scope = null (simulate pre-migration). We insert PG and
     * AllocationRow directly so we do not mutate immutable rows.
     */
    private function createLegacyStylePartyOnlyExpense(string $classification, float $amount): OperationalTransaction
    {
        $txn = OperationalTransaction::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'type' => 'EXPENSE',
            'status' => 'DRAFT',
            'transaction_date' => '2024-06-15',
            'amount' => $amount,
            'classification' => $classification,
        ]);

        $pg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'OPERATIONAL',
            'source_id' => $txn->id,
            'posting_date' => '2024-06-15',
        ]);

        $txn->update(['status' => 'POSTED', 'posting_group_id' => $pg->id]);

        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'project_id' => $this->project->id,
            'party_id' => $this->project->party_id,
            'allocation_type' => 'POOL_SHARE',
            'allocation_scope' => null,
            'amount' => (string) $amount,
            'rule_snapshot' => ['classification' => $classification],
        ]);

        $expAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'EXP_SHARED')->first();
        $cashAccount = Account::where('tenant_id', $this->tenant->id)->where('code', 'CASH')->first();
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $expAccount->id,
            'debit_amount' => $amount,
            'credit_amount' => 0,
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $pg->id,
            'account_id' => $cashAccount->id,
            'debit_amount' => 0,
            'credit_amount' => $amount,
            'currency_code' => 'GBP',
        ]);

        return $txn->fresh();
    }

    public function test_command_creates_correction_posting_group_with_two_allocations(): void
    {
        $txn = $this->createLegacyStylePartyOnlyExpense('HARI_ONLY', 75.00);

        $this->artisan('accounting:reclassify-legacy-party-only-expenses')
            ->assertExitCode(0);

        $reclass = ReclassCorrection::where('operational_transaction_id', $txn->id)->first();
        $this->assertNotNull($reclass);
        $correctionPg = $reclass->postingGroup;
        $this->assertNotNull($correctionPg);
        $this->assertEquals('ACCOUNTING_CORRECTION', $correctionPg->source_type);

        $rows = AllocationRow::where('posting_group_id', $correctionPg->id)->orderBy('amount')->get();
        $this->assertCount(2, $rows);
        $negative = $rows->first(fn ($r) => (float) $r->amount < 0);
        $positive = $rows->first(fn ($r) => (float) $r->amount > 0);
        $this->assertNotNull($negative);
        $this->assertNotNull($positive);
        $this->assertEquals('SHARED', $negative->allocation_scope);
        $this->assertEquals('HARI_ONLY', $positive->allocation_scope);
        $this->assertEqualsWithDelta(75.0, abs((float) $negative->amount), 0.01);
        $this->assertEqualsWithDelta(75.0, (float) $positive->amount, 0.01);
    }

    public function test_command_is_idempotent(): void
    {
        $txn = $this->createLegacyStylePartyOnlyExpense('LANDLORD_ONLY', 50.00);

        $this->artisan('accounting:reclassify-legacy-party-only-expenses')->assertExitCode(0);
        $countFirst = ReclassCorrection::where('operational_transaction_id', $txn->id)->count();

        $this->artisan('accounting:reclassify-legacy-party-only-expenses')->assertExitCode(0);
        $countSecond = ReclassCorrection::where('operational_transaction_id', $txn->id)->count();

        $this->assertEquals(1, $countFirst);
        $this->assertEquals(1, $countSecond, 'Second run must not create duplicate correction');
    }

    public function test_command_does_not_alter_original_posting_group(): void
    {
        $txn = $this->createLegacyStylePartyOnlyExpense('HARI_ONLY', 30.00);
        $originalPgId = $txn->posting_group_id;
        $originalRowIds = AllocationRow::where('posting_group_id', $originalPgId)->pluck('id')->all();

        $this->artisan('accounting:reclassify-legacy-party-only-expenses')->assertExitCode(0);

        $originalRowsAfter = AllocationRow::where('posting_group_id', $originalPgId)->get();
        $this->assertCount(count($originalRowIds), $originalRowsAfter);
        $this->assertEqualsCanonicalizing($originalRowIds, $originalRowsAfter->pluck('id')->all());
    }
}
