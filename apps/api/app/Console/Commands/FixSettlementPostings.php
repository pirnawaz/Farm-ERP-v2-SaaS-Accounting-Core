<?php

namespace App\Console\Commands;

use App\Models\AccountingCorrection;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\SystemAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Find posting groups where operational source types (initially INVENTORY_ISSUE)
 * contain PROFIT_DISTRIBUTION or PROFIT_DISTRIBUTION_CLEARING (architectural bug),
 * and create reversal + corrected posting groups. Idempotent via accounting_corrections table.
 *
 * Run with --dry-run to only report and show would-be IDs; without to apply corrections.
 */
class FixSettlementPostings extends Command
{
    protected $signature = 'accounting:fix-settlement-postings
                            {--dry-run : Only report, do not create reversal/corrected groups}
                            {--tenant= : Limit to tenant ID}
                            {--only-pg= : Fix only this posting group ID}
                            {--limit= : Max number of posting groups to fix per run}';

    protected $description = 'Find and correct operational postings that incorrectly used PROFIT_DISTRIBUTION or PROFIT_DISTRIBUTION_CLEARING';

    /** Source types we can auto-correct. Only INVENTORY_ISSUE for now. */
    private const CORRECTABLE_SOURCE_TYPES = ['INVENTORY_ISSUE'];

    public function __construct(
        private SystemAccountService $accountService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantFilter = $this->option('tenant');
        $onlyPg = $this->option('only-pg');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $query = PostingGroup::query()
            ->whereIn('source_type', self::CORRECTABLE_SOURCE_TYPES)
            ->where(function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('ledger_entries')
                        ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
                        ->whereColumn('ledger_entries.posting_group_id', 'posting_groups.id')
                        ->whereIn('accounts.code', ['PROFIT_DISTRIBUTION', 'PROFIT_DISTRIBUTION_CLEARING']);
                });
            });

        if ($tenantFilter) {
            $query->where('tenant_id', $tenantFilter);
        }
        if ($onlyPg) {
            $query->where('id', $onlyPg);
        }
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $candidateGroups = $query->with(['ledgerEntries.account', 'allocationRows', 'tenant'])->get();

        if ($candidateGroups->isEmpty()) {
            $this->info('No posting groups found with operational source_type and PROFIT_DISTRIBUTION/PROFIT_DISTRIBUTION_CLEARING entries.');
            return self::SUCCESS;
        }

        // Exclude already corrected
        $alreadyCorrectedIds = AccountingCorrection::whereIn('original_posting_group_id', $candidateGroups->pluck('id'))
            ->when($tenantFilter, fn ($q) => $q->where('tenant_id', $tenantFilter))
            ->pluck('original_posting_group_id')
            ->all();

        $badGroups = $candidateGroups->filter(fn ($pg) => ! in_array($pg->id, $alreadyCorrectedIds, true));

        foreach ($candidateGroups as $pg) {
            if (in_array($pg->id, $alreadyCorrectedIds, true)) {
                $this->line(sprintf('  [skip] PG %s already corrected', $pg->id));
            }
        }

        if ($badGroups->isEmpty()) {
            $this->info('All matching posting groups are already corrected.');
            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d posting group(s) to correct.', $badGroups->count()));

        if ($dryRun) {
            foreach ($badGroups as $pg) {
                $reversalId = (string) Str::uuid();
                $correctedId = (string) Str::uuid();
                $this->line(sprintf(
                    '  Would create: original_pg=%s reversal_pg=%s corrected_pg=%s posting_date=%s',
                    $pg->id,
                    $reversalId,
                    $correctedId,
                    $pg->posting_date?->format('Y-m-d')
                ));
            }
            $this->info('Dry run: no changes made. Run without --dry-run to create reversal and corrected posting groups.');
            return self::SUCCESS;
        }

        $correctedCount = 0;
        foreach ($badGroups as $originalPg) {
            try {
                $this->fixOnePostingGroup($originalPg);
                $correctedCount++;
                $this->line(sprintf(
                    '  Corrected: original_pg=%s reversal_pg=%s corrected_pg=%s posting_date=%s',
                    $originalPg->id,
                    AccountingCorrection::where('original_posting_group_id', $originalPg->id)->value('reversal_posting_group_id'),
                    AccountingCorrection::where('original_posting_group_id', $originalPg->id)->value('corrected_posting_group_id'),
                    $originalPg->posting_date?->format('Y-m-d')
                ));
            } catch (\Throwable $e) {
                $this->error(sprintf('Failed to correct PG %s: %s', $originalPg->id, $e->getMessage()));
                report($e);
            }
        }

        $this->info(sprintf('Corrected %d posting group(s).', $correctedCount));
        return self::SUCCESS;
    }

    private function fixOnePostingGroup(PostingGroup $originalPg): void
    {
        DB::transaction(function () use ($originalPg) {
            $tenantId = $originalPg->tenant_id;
            $postingDate = $originalPg->posting_date->format('Y-m-d');

            if (AccountingCorrection::where('tenant_id', $tenantId)->where('original_posting_group_id', $originalPg->id)->exists()) {
                return;
            }

            $reversalPg = $this->createReversalPostingGroup($originalPg);
            $correctedPg = $this->createCorrectedPostingGroup($originalPg);

            AccountingCorrection::create([
                'tenant_id' => $tenantId,
                'original_posting_group_id' => $originalPg->id,
                'reversal_posting_group_id' => $reversalPg->id,
                'corrected_posting_group_id' => $correctedPg->id,
                'reason' => AccountingCorrection::REASON_OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION,
                'correction_batch_run_at' => now(),
            ]);
        });
    }

    private function createReversalPostingGroup(PostingGroup $originalPg): PostingGroup
    {
        $tenantId = $originalPg->tenant_id;
        $postingDate = $originalPg->posting_date->format('Y-m-d');

        $reversalPg = PostingGroup::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $originalPg->crop_cycle_id,
            'source_type' => 'ACCOUNTING_CORRECTION_REVERSAL',
            'source_id' => $originalPg->id,
            'posting_date' => $postingDate,
            'reversal_of_posting_group_id' => $originalPg->id,
            'correction_reason' => 'OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION',
        ]);

        foreach ($originalPg->ledgerEntries as $entry) {
            LedgerEntry::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $reversalPg->id,
                'account_id' => $entry->account_id,
                'debit_amount' => $entry->credit_amount,
                'credit_amount' => $entry->debit_amount,
                'currency_code' => $entry->currency_code ?? 'GBP',
            ]);
        }

        foreach ($originalPg->allocationRows as $row) {
            $snapshot = is_array($row->rule_snapshot) ? $row->rule_snapshot : [];
            $snapshot['correction_reversal_of'] = $originalPg->id;
            $snapshot['correction_reason'] = 'OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION';
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $reversalPg->id,
                'project_id' => $row->project_id,
                'party_id' => $row->party_id,
                'allocation_type' => $row->allocation_type,
                'amount' => $row->amount,
                'machine_id' => $row->machine_id,
                'rule_snapshot' => $snapshot,
            ]);
        }

        return $reversalPg->fresh(['ledgerEntries.account', 'allocationRows']);
    }

    private function createCorrectedPostingGroup(PostingGroup $originalPg): PostingGroup
    {
        $tenantId = $originalPg->tenant_id;
        $postingDate = $originalPg->posting_date->format('Y-m-d');

        $totalValue = $this->getIssueTotalValueFromLedger($originalPg);
        if ($totalValue < 0.001) {
            throw new \Exception(sprintf('Original PG %s has no INPUTS_EXPENSE debit / INVENTORY_INPUTS credit; cannot build corrected posting.', $originalPg->id));
        }

        $inputsExpenseAccount = $this->accountService->getByCode($tenantId, 'INPUTS_EXPENSE');
        $inventoryAccount = $this->accountService->getByCode($tenantId, 'INVENTORY_INPUTS');
        $totalValueStr = (string) round($totalValue, 2);

        $correctedPg = PostingGroup::create([
            'tenant_id' => $tenantId,
            'crop_cycle_id' => $originalPg->crop_cycle_id,
            'source_type' => 'ACCOUNTING_CORRECTION',
            'source_id' => $originalPg->id,
            'posting_date' => $postingDate,
            'correction_reason' => 'OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION',
        ]);

        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $correctedPg->id,
            'account_id' => $inputsExpenseAccount->id,
            'debit_amount' => $totalValueStr,
            'credit_amount' => '0',
            'currency_code' => 'GBP',
        ]);
        LedgerEntry::create([
            'tenant_id' => $tenantId,
            'posting_group_id' => $correctedPg->id,
            'account_id' => $inventoryAccount->id,
            'debit_amount' => '0',
            'credit_amount' => $totalValueStr,
            'currency_code' => 'GBP',
        ]);

        foreach ($originalPg->allocationRows as $row) {
            $snapshot = is_array($row->rule_snapshot) ? $row->rule_snapshot : [];
            $snapshot['correction_of_pg'] = $originalPg->id;
            $snapshot['correction_reason'] = 'OPERATIONAL_PG_CONTAINS_PROFIT_DISTRIBUTION';
            AllocationRow::create([
                'tenant_id' => $tenantId,
                'posting_group_id' => $correctedPg->id,
                'project_id' => $row->project_id,
                'party_id' => $row->party_id,
                'allocation_type' => $row->allocation_type,
                'amount' => $row->amount,
                'machine_id' => $row->machine_id,
                'rule_snapshot' => $snapshot,
            ]);
        }

        return $correctedPg->fresh(['ledgerEntries.account', 'allocationRows']);
    }

    /**
     * Derive total issue value from original PG ledger (INPUTS_EXPENSE debit or INVENTORY_INPUTS credit).
     */
    private function getIssueTotalValueFromLedger(PostingGroup $originalPg): float
    {
        $totalFromExpense = $originalPg->ledgerEntries
            ->filter(fn ($e) => $e->account && $e->account->code === 'INPUTS_EXPENSE')
            ->sum('debit_amount');
        if ($totalFromExpense >= 0.001) {
            return (float) $totalFromExpense;
        }
        $totalFromInventory = $originalPg->ledgerEntries
            ->filter(fn ($e) => $e->account && $e->account->code === 'INVENTORY_INPUTS')
            ->sum('credit_amount');
        return (float) $totalFromInventory;
    }
}
