<?php

namespace Tests\Feature;

use App\Models\AccountingCorrection;
use App\Models\AllocationRow;
use App\Models\Account;
use App\Models\CropCycle;
use App\Models\LedgerEntry;
use App\Models\Party;
use App\Models\PostingGroup;
use App\Models\Project;
use App\Models\ProjectRule;
use App\Models\Tenant;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature test for automated fix-settlement-postings command:
 * - Creates bad INVENTORY_ISSUE PG with PROFIT_DISTRIBUTION
 * - Runs command; asserts reversal + corrected PGs and idempotency
 */
class FixSettlementPostingsAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private CropCycle $cropCycle;
    private Project $project;
    private Party $hariParty;

    protected function setUp(): void
    {
        parent::setUp();
        (new ModulesSeeder)->run();
        $this->tenant = Tenant::create(['name' => 'Test Tenant', 'status' => 'active']);
        SystemAccountsSeeder::runForTenant($this->tenant->id);

        $this->cropCycle = CropCycle::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Wheat 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'OPEN',
        ]);
        $this->hariParty = Party::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Hari',
            'party_types' => ['HARI'],
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'party_id' => $this->hariParty->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'name' => 'Test Project',
            'status' => 'ACTIVE',
        ]);
        ProjectRule::create([
            'project_id' => $this->project->id,
            'profit_split_landlord_pct' => 60.00,
            'profit_split_hari_pct' => 40.00,
            'kamdari_pct' => 0.00,
            'kamdari_order' => 'BEFORE_SPLIT',
            'pool_definition' => 'REVENUE_MINUS_SHARED_COSTS',
        ]);
    }

    /**
     * Create a bad INVENTORY_ISSUE posting group (operational + PROFIT_DISTRIBUTION) and run fix command.
     * Assert: exactly two new PGs (reversal + corrected), reversal is inverse, corrected is operational-only, idempotent.
     */
    public function test_command_creates_reversal_and_corrected_and_is_idempotent(): void
    {
        $accounts = Account::where('tenant_id', $this->tenant->id)
            ->whereIn('code', ['INPUTS_EXPENSE', 'INVENTORY_INPUTS', 'PARTY_CONTROL_HARI', 'PROFIT_DISTRIBUTION'])
            ->get()
            ->keyBy('code');

        $badPg = PostingGroup::create([
            'tenant_id' => $this->tenant->id,
            'crop_cycle_id' => $this->cropCycle->id,
            'source_type' => 'INVENTORY_ISSUE',
            'source_id' => (string) Str::uuid(),
            'posting_date' => '2024-06-15',
        ]);

        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $badPg->id,
            'account_id' => $accounts['INPUTS_EXPENSE']->id,
            'debit_amount' => '100.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $badPg->id,
            'account_id' => $accounts['INVENTORY_INPUTS']->id,
            'debit_amount' => '0',
            'credit_amount' => '100.00',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $badPg->id,
            'account_id' => $accounts['PARTY_CONTROL_HARI']->id,
            'debit_amount' => '100.00',
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $badPg->id,
            'account_id' => $accounts['PROFIT_DISTRIBUTION']->id,
            'debit_amount' => '0',
            'credit_amount' => '100.00',
            'currency_code' => 'GBP',
        ]);

        AllocationRow::create([
            'tenant_id' => $this->tenant->id,
            'posting_group_id' => $badPg->id,
            'project_id' => $this->project->id,
            'party_id' => $this->hariParty->id,
            'allocation_type' => 'HARI_ONLY',
            'amount' => '100.00',
            'rule_snapshot' => ['source' => 'inv_issue', 'allocation_mode' => 'HARI_ONLY'],
        ]);

        $pgCountBefore = PostingGroup::count();
        $correctionCountBefore = AccountingCorrection::count();

        $this->artisan('accounting:fix-settlement-postings', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();

        $this->assertEquals($correctionCountBefore + 1, AccountingCorrection::count(), 'Exactly one correction record');
        $this->assertEquals($pgCountBefore + 2, PostingGroup::count(), 'Exactly two new PGs (reversal + corrected)');

        $correction = AccountingCorrection::where('original_posting_group_id', $badPg->id)->first();
        $this->assertNotNull($correction);
        $this->assertEquals($badPg->id, $correction->original_posting_group_id);
        $this->assertEquals(AccountingCorrection::REASON_OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION, $correction->reason);

        $reversalPg = $correction->reversalPostingGroup()->with('ledgerEntries.account')->first();
        $correctedPg = $correction->correctedPostingGroup()->with('ledgerEntries.account')->first();

        $this->assertEquals('ACCOUNTING_CORRECTION_REVERSAL', $reversalPg->source_type);
        $this->assertEquals($badPg->id, $reversalPg->source_id);
        $this->assertReversalIsInverseOf($badPg->fresh(['ledgerEntries.account']), $reversalPg);

        $this->assertEquals('ACCOUNTING_CORRECTION', $correctedPg->source_type);
        $this->assertEquals($badPg->id, $correctedPg->source_id);
        $this->assertCorrectedIsOperationalOnly($correctedPg);

        $pgCountAfterFirst = PostingGroup::count();
        $this->artisan('accounting:fix-settlement-postings', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();
        $this->assertEquals($pgCountAfterFirst, PostingGroup::count(), 'Idempotent: no new PGs on second run');
        $this->assertEquals(1, AccountingCorrection::where('original_posting_group_id', $badPg->id)->count(), 'Still one correction record');
    }

    private function assertReversalIsInverseOf(PostingGroup $original, PostingGroup $reversal): void
    {
        $originalEntries = $original->ledgerEntries->keyBy('id');
        $reversalByAccount = $reversal->ledgerEntries->groupBy('account_id');
        foreach ($original->ledgerEntries as $orig) {
            $revEntries = $reversalByAccount->get($orig->account_id, collect());
            $rev = $revEntries->first();
            $this->assertNotNull($rev, 'Reversal should have entry for account ' . $orig->account->code);
            $this->assertEquals((float) $orig->credit_amount, (float) $rev->debit_amount, 'Reversal debit should equal original credit');
            $this->assertEquals((float) $orig->debit_amount, (float) $rev->credit_amount, 'Reversal credit should equal original debit');
        }
        $this->assertCount($original->ledgerEntries->count(), $reversal->ledgerEntries, 'Reversal should have same number of entries');
    }

    private function assertCorrectedIsOperationalOnly(PostingGroup $correctedPg): void
    {
        $codes = $correctedPg->ledgerEntries->map(fn ($e) => $e->account->code)->all();
        $this->assertCount(2, $correctedPg->ledgerEntries, 'Corrected PG must have exactly 2 ledger entries');
        $this->assertContains('INPUTS_EXPENSE', $codes);
        $this->assertContains('INVENTORY_INPUTS', $codes);
        $this->assertNotContains('PROFIT_DISTRIBUTION', $codes);
        $this->assertNotContains('PROFIT_DISTRIBUTION_CLEARING', $codes);
        foreach (['PARTY_CONTROL_HARI', 'PARTY_CONTROL_LANDLORD', 'PARTY_CONTROL_KAMDAR'] as $control) {
            $this->assertNotContains($control, $codes, "Corrected PG must not have $control");
        }
        $inputsExpenseDebit = $correctedPg->ledgerEntries->filter(fn ($e) => $e->account->code === 'INPUTS_EXPENSE')->sum('debit_amount');
        $inventoryCredit = $correctedPg->ledgerEntries->filter(fn ($e) => $e->account->code === 'INVENTORY_INPUTS')->sum('credit_amount');
        $this->assertEquals(100.00, (float) $inputsExpenseDebit, 'Expense must equal full issue value');
        $this->assertEquals(100.00, (float) $inventoryCredit, 'Inventory credit must equal full issue value');
    }
}
