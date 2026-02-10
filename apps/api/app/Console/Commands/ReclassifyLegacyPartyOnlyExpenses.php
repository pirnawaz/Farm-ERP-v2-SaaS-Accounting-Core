<?php

namespace App\Console\Commands;

use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\OperationalTransaction;
use App\Models\PostingGroup;
use App\Models\ReclassCorrection;
use App\Services\SystemAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time reclass of legacy OPERATIONAL expenses (HARI_ONLY/LANDLORD_ONLY) that were
 * posted with SHARED or null allocation_scope. Creates one ACCOUNTING_CORRECTION
 * PostingGroup per candidate with two AllocationRows (-SHARED, +party_only) and
 * balanced clearing ledger entries. Idempotent via reclass_corrections table.
 */
class ReclassifyLegacyPartyOnlyExpenses extends Command
{
    protected $signature = 'accounting:reclassify-legacy-party-only-expenses
                            {--dry-run : Only report candidates and would-create IDs}
                            {--tenant= : Limit to tenant ID}
                            {--project= : Limit to project ID}
                            {--from= : Posting date from (YYYY-MM-DD)}
                            {--to= : Posting date to (YYYY-MM-DD)}';

    protected $description = 'Reclassify legacy HARI_ONLY/LANDLORD_ONLY expenses posted as SHARED via correction PostingGroups';

    public function __construct(
        private SystemAccountService $accountService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantFilter = $this->option('tenant');
        $projectFilter = $this->option('project');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');

        $query = OperationalTransaction::query()
            ->where('type', 'EXPENSE')
            ->where('status', 'POSTED')
            ->whereIn('classification', ['HARI_ONLY', 'LANDLORD_ONLY'])
            ->whereNotNull('posting_group_id')
            ->with(['postingGroup.allocationRows']);

        if ($tenantFilter) {
            $query->where('tenant_id', $tenantFilter);
        }
        if ($projectFilter) {
            $query->where('project_id', $projectFilter);
        }

        $candidates = $query->get();

        $filtered = $candidates->filter(function (OperationalTransaction $txn) use ($fromDate, $toDate): bool {
            $pg = $txn->postingGroup;
            if (! $pg) {
                return false;
            }
            $postingDate = $pg->posting_date ? (\Carbon\Carbon::parse($pg->posting_date)->format('Y-m-d')) : null;
            if ($fromDate && $postingDate && $postingDate < $fromDate) {
                return false;
            }
            if ($toDate && $postingDate && $postingDate > $toDate) {
                return false;
            }
            $rows = $pg->allocationRows;
            $allScopesNullOrShared = $rows->isEmpty() || $rows->every(fn ($r) => $r->allocation_scope === null || $r->allocation_scope === 'SHARED');
            if (! $allScopesNullOrShared) {
                return false;
            }
            $exists = ReclassCorrection::where('operational_transaction_id', $txn->id)->exists();
            return ! $exists;
        });

        if ($filtered->isEmpty()) {
            $this->info('No legacy party-only expense candidates found (or all already corrected).');
            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d candidate(s) to reclassify.', $filtered->count()));

        if ($dryRun) {
            foreach ($filtered as $txn) {
                $this->line(sprintf(
                    '  txn_id=%s amount=%s classification=%s would create correction PG (source_id=RECLASSIFY_OP_TXN:%s)',
                    $txn->id,
                    $txn->amount,
                    $txn->classification,
                    $txn->id
                ));
            }
            $this->info('Dry run: no changes made. Run without --dry-run to create correction posting groups.');
            return self::SUCCESS;
        }

        $created = 0;
        foreach ($filtered as $txn) {
            try {
                $this->createCorrectionForTransaction($txn);
                $created++;
                $this->line(sprintf('  Corrected txn_id=%s', $txn->id));
            } catch (\Throwable $e) {
                $this->error(sprintf('  Failed txn_id=%s: %s', $txn->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('Created %d correction posting group(s).', $created));
        return self::SUCCESS;
    }

    private function createCorrectionForTransaction(OperationalTransaction $txn): void
    {
        DB::transaction(function () use ($txn) {
            if (ReclassCorrection::where('operational_transaction_id', $txn->id)->exists()) {
                return;
            }

            $pg = $txn->postingGroup;
            if (! $pg) {
                throw new \Exception('Transaction has no posting group');
            }

            $amount = (float) $txn->amount;
            if ($amount < 0.01) {
                throw new \Exception('Transaction amount too small');
            }

            $postingDate = $pg->posting_date ? \Carbon\Carbon::parse($pg->posting_date)->format('Y-m-d') : $txn->transaction_date->format('Y-m-d');
            $projectId = $txn->project_id ?? $pg->allocationRows->first()?->project_id;
            $partyId = $pg->allocationRows->first()?->party_id;
            if (! $projectId || ! $partyId) {
                throw new \Exception('Missing project_id or party_id from original posting group');
            }

            $correctionPg = PostingGroup::create([
                'tenant_id' => $txn->tenant_id,
                'crop_cycle_id' => $pg->crop_cycle_id,
                'source_type' => 'ACCOUNTING_CORRECTION',
                'source_id' => (string) Str::uuid(),
                'posting_date' => $postingDate,
            ]);

            ReclassCorrection::create([
                'tenant_id' => $txn->tenant_id,
                'operational_transaction_id' => $txn->id,
                'posting_group_id' => $correctionPg->id,
            ]);

            $description = sprintf('Reclassify legacy expense scope to %s for txn %s', $txn->classification, $txn->id);
            $ruleSnapshot = ['reclass_of_txn_id' => $txn->id, 'description' => $description];

            AllocationRow::create([
                'tenant_id' => $txn->tenant_id,
                'posting_group_id' => $correctionPg->id,
                'project_id' => $projectId,
                'party_id' => $partyId,
                'allocation_type' => 'POOL_SHARE',
                'allocation_scope' => 'SHARED',
                'amount' => -$amount,
                'rule_snapshot' => array_merge($ruleSnapshot, ['direction' => 'reduce_shared']),
            ]);

            AllocationRow::create([
                'tenant_id' => $txn->tenant_id,
                'posting_group_id' => $correctionPg->id,
                'project_id' => $projectId,
                'party_id' => $partyId,
                'allocation_type' => $txn->classification,
                'allocation_scope' => $txn->classification,
                'amount' => $amount,
                'rule_snapshot' => array_merge($ruleSnapshot, ['direction' => 'add_party_only']),
            ]);

            $clearingAccount = $this->accountService->getByCode($txn->tenant_id, 'EXPENSE_RECLASS_CLEARING');
            $offsetAccount = $this->accountService->getByCode($txn->tenant_id, 'EXPENSE_RECLASS_OFFSET');
            $amountStr = (string) round($amount, 2);

            LedgerEntry::create([
                'tenant_id' => $txn->tenant_id,
                'posting_group_id' => $correctionPg->id,
                'account_id' => $clearingAccount->id,
                'debit_amount' => $amountStr,
                'credit_amount' => '0',
                'currency_code' => 'GBP',
            ]);
            LedgerEntry::create([
                'tenant_id' => $txn->tenant_id,
                'posting_group_id' => $correctionPg->id,
                'account_id' => $offsetAccount->id,
                'debit_amount' => '0',
                'credit_amount' => $amountStr,
                'currency_code' => 'GBP',
            ]);
        });
    }
}
