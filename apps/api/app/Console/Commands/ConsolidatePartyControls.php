<?php

namespace App\Console\Commands;

use App\Models\AccountingCorrection;
use App\Models\AllocationRow;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Models\Account;
use App\Services\PartyAccountService;
use App\Services\SystemAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Consolidate legacy party balance accounts into new PARTY_CONTROL_* accounts.
 * 
 * Moves balances from:
 * - HARI: ADVANCE_HARI, DUE_FROM_HARI, PAYABLE_HARI -> PARTY_CONTROL_HARI
 * - LANDLORD: PAYABLE_LANDLORD, ADVANCE_LANDLORD, DUE_FROM_LANDLORD -> PARTY_CONTROL_LANDLORD
 * - KAMDAR: PAYABLE_KAMDAR, ADVANCE_KAMDAR, DUE_FROM_KAMDAR -> PARTY_CONTROL_KAMDAR
 * 
 * Idempotent via accounting_corrections table (tenant_id + reason unique).
 */
class ConsolidatePartyControls extends Command
{
    protected $signature = 'accounting:consolidate-party-controls
                            {--tenant= : Tenant UUID (required)}
                            {--posting-date= : Posting date (YYYY-MM-DD), defaults to today}
                            {--dry-run : Only report what would be posted, no changes}
                            {--limit= : Limit number of roles to process (for testing)}';

    protected $description = 'Consolidate legacy party accounts into PARTY_CONTROL_* accounts';

    // Legacy account codes by role
    private const LEGACY_ACCOUNTS = [
        'HARI' => ['ADVANCE_HARI', 'DUE_FROM_HARI', 'PAYABLE_HARI'],
        'LANDLORD' => ['PAYABLE_LANDLORD', 'ADVANCE_LANDLORD', 'DUE_FROM_LANDLORD'],
        'KAMDAR' => ['PAYABLE_KAMDAR', 'ADVANCE_KAMDAR', 'DUE_FROM_KAMDAR'],
    ];

    public function __construct(
        private SystemAccountService $accountService,
        private PartyAccountService $partyAccountService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        if (!$tenantId) {
            $this->error('--tenant is required');
            return self::FAILURE;
        }

        $postingDateStr = $this->option('posting-date') ?: Carbon::today()->format('Y-m-d');
        try {
            $postingDate = Carbon::parse($postingDateStr);
        } catch (\Exception $e) {
            $this->error("Invalid posting-date format: {$postingDateStr}. Use YYYY-MM-DD");
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Check if already consolidated
        $existing = AccountingCorrection::where('tenant_id', $tenantId)
            ->where('reason', AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION)
            ->first();

        if ($existing) {
            $this->info(sprintf(
                'Consolidation already completed for tenant %s (PostingGroup: %s, Date: %s)',
                $tenantId,
                $existing->corrected_posting_group_id,
                $existing->correction_batch_run_at?->format('Y-m-d')
            ));
            return self::SUCCESS;
        }

        // Collect balances for each role
        $consolidations = [];
        $roles = array_keys(self::LEGACY_ACCOUNTS);
        if ($limit) {
            $roles = array_slice($roles, 0, $limit);
        }

        foreach ($roles as $role) {
            $legacyCodes = self::LEGACY_ACCOUNTS[$role];
            $balances = $this->calculateLegacyBalances($tenantId, $legacyCodes, $postingDate);

            // Filter out zero balances
            $nonZeroBalances = array_filter($balances, fn($bal) => abs($bal) >= 0.01);

            if (!empty($nonZeroBalances)) {
                try {
                    $controlAccount = $this->partyAccountService->getPartyControlAccountByRole($tenantId, $role);
                    $consolidations[$role] = [
                        'control_account' => $controlAccount,
                        'legacy_balances' => $nonZeroBalances,
                    ];
                } catch (\Exception $e) {
                    $this->warn(sprintf('Skipping role %s: %s', $role, $e->getMessage()));
                }
            }
        }

        if (empty($consolidations)) {
            $this->info('No legacy balances found to consolidate.');
            return self::SUCCESS;
        }

        // Display what will be consolidated
        $this->info(sprintf('Found balances to consolidate for %d role(s):', count($consolidations)));
        foreach ($consolidations as $role => $data) {
            $this->line(sprintf('  %s -> %s:', $role, $data['control_account']->code));
            foreach ($data['legacy_balances'] as $accountCode => $balance) {
                $this->line(sprintf('    %s: %s', $accountCode, number_format($balance, 2)));
            }
        }

        if ($dryRun) {
            $this->info('Dry run: no changes made. Run without --dry-run to create consolidation posting group.');
            return self::SUCCESS;
        }

        // Create consolidation posting group
        try {
            $postingGroup = $this->createConsolidationPostingGroup(
                $tenantId,
                $postingDate->format('Y-m-d'),
                $consolidations
            );

            $this->info(sprintf(
                'Consolidation completed: PostingGroup %s created on %s',
                $postingGroup->id,
                $postingDate->format('Y-m-d')
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error(sprintf('Failed to create consolidation: %s', $e->getMessage()));
            report($e);
            return self::FAILURE;
        }
    }

    /**
     * Calculate net balance for legacy accounts up to posting_date.
     * Returns array: ['ACCOUNT_CODE' => net_balance]
     */
    private function calculateLegacyBalances(string $tenantId, array $accountCodes, Carbon $postingDate): array
    {
        // Get account IDs
        $accounts = Account::where('tenant_id', $tenantId)
            ->whereIn('code', $accountCodes)
            ->get()
            ->keyBy('code');

        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = [];

        foreach ($accounts as $code => $account) {
            // Calculate net balance: SUM(debit_amount - credit_amount) up to posting_date
            $netBalance = LedgerEntry::where('tenant_id', $tenantId)
                ->where('account_id', $account->id)
                ->whereHas('postingGroup', function ($q) use ($postingDate) {
                    $q->where('posting_date', '<=', $postingDate->format('Y-m-d'));
                })
                ->selectRaw('COALESCE(SUM(debit_amount::numeric - credit_amount::numeric), 0) as net')
                ->value('net');

            $balances[$code] = (float) $netBalance;
        }

        return $balances;
    }

    /**
     * Create consolidation PostingGroup with LedgerEntries and AllocationRows.
     */
    private function createConsolidationPostingGroup(
        string $tenantId,
        string $postingDate,
        array $consolidations
    ): PostingGroup {
        return DB::transaction(function () use ($tenantId, $postingDate, $consolidations) {
            // Create PostingGroup (source_id required NOT NULL; use UUID for consolidation run)
            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null, // No crop cycle for consolidation
                'source_type' => 'ACCOUNTING_CORRECTION',
                'source_id' => (string) Str::uuid(),
                'posting_date' => $postingDate,
                'correction_reason' => AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION,
            ]);

            $ledgerEntries = [];
            $allocationRows = [];

            // Process each role
            foreach ($consolidations as $role => $data) {
                $controlAccount = $data['control_account'];
                $legacyBalances = $data['legacy_balances'];

                // Get legacy account objects
                $legacyAccounts = Account::where('tenant_id', $tenantId)
                    ->whereIn('code', array_keys($legacyBalances))
                    ->get()
                    ->keyBy('code');

                $roleMovedAccounts = [];

                foreach ($legacyBalances as $accountCode => $netBalance) {
                    if (abs($netBalance) < 0.01) {
                        continue;
                    }

                    $legacyAccount = $legacyAccounts->get($accountCode);
                    if (!$legacyAccount) {
                        continue;
                    }

                    $roleMovedAccounts[] = [
                        'code' => $accountCode,
                        'balance' => $netBalance,
                    ];

                    if ($netBalance > 0) {
                        // Debit balance: Cr legacy_account, Dr PARTY_CONTROL_*
                        $ledgerEntries[] = [
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $legacyAccount->id,
                            'debit_amount' => '0',
                            'credit_amount' => (string) round($netBalance, 2),
                            'currency_code' => 'GBP',
                        ];
                        $ledgerEntries[] = [
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $controlAccount->id,
                            'debit_amount' => (string) round($netBalance, 2),
                            'credit_amount' => '0',
                            'currency_code' => 'GBP',
                        ];
                    } else {
                        // Credit balance: Dr legacy_account, Cr PARTY_CONTROL_*
                        $absBalance = abs($netBalance);
                        $ledgerEntries[] = [
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $legacyAccount->id,
                            'debit_amount' => (string) round($absBalance, 2),
                            'credit_amount' => '0',
                            'currency_code' => 'GBP',
                        ];
                        $ledgerEntries[] = [
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'account_id' => $controlAccount->id,
                            'debit_amount' => '0',
                            'credit_amount' => (string) round($absBalance, 2),
                            'currency_code' => 'GBP',
                        ];
                    }
                }

                // Create AllocationRow for this role
                $totalMoved = array_sum(array_column($roleMovedAccounts, 'balance'));

                if (abs($totalMoved) >= 0.01) {
                    // Find a project for allocation row (required by schema; party_id also NOT NULL)
                    $project = \App\Models\Project::where('tenant_id', $tenantId)->first();
                    
                    if ($project && $project->party_id) {
                        $allocationRows[] = [
                            'tenant_id' => $tenantId,
                            'posting_group_id' => $postingGroup->id,
                            'project_id' => $project->id,
                            'party_id' => $project->party_id,
                            'allocation_type' => 'PARTY_CONTROL_CONSOLIDATION',
                            'amount' => (string) round(abs($totalMoved), 2),
                            'rule_snapshot' => [
                                'consolidation_role' => $role,
                                'consolidation_reason' => AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION,
                                'moved_accounts' => $roleMovedAccounts,
                            ],
                        ];
                    }
                }
            }

            // Create all ledger entries
            foreach ($ledgerEntries as $entry) {
                LedgerEntry::create($entry);
            }

            // Create all allocation rows
            foreach ($allocationRows as $row) {
                AllocationRow::create($row);
            }

            // Verify debits == credits
            $totalDebits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('debit_amount');
            $totalCredits = LedgerEntry::where('posting_group_id', $postingGroup->id)
                ->sum('credit_amount');

            if (abs($totalDebits - $totalCredits) > 0.01) {
                throw new \Exception(sprintf(
                    'Debits and credits do not balance: debits=%s, credits=%s',
                    $totalDebits,
                    $totalCredits
                ));
            }

            // Record in accounting_corrections
            AccountingCorrection::create([
                'tenant_id' => $tenantId,
                'original_posting_group_id' => null,
                'reversal_posting_group_id' => null,
                'corrected_posting_group_id' => $postingGroup->id,
                'reason' => AccountingCorrection::REASON_PARTY_CONTROL_CONSOLIDATION,
                'correction_batch_run_at' => now(),
            ]);

            return $postingGroup->fresh(['ledgerEntries.account', 'allocationRows']);
        });
    }
}
