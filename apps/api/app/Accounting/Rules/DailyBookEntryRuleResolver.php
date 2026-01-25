<?php

namespace App\Accounting\Rules;

use App\Models\DailyBookEntry;
use App\Models\DailyBookAccountMapping;
use App\Models\Account;
use Carbon\Carbon;

class DailyBookEntryRuleResolver implements RuleResolver
{
    /**
     * Resolve rules for a DailyBookEntry at posting time.
     * 
     * @param string $tenantId
     * @param string $dailyBookEntryId
     * @param string $postingDate YYYY-MM-DD format
     * @return RuleResolutionResult
     */
    public function resolveDailyBookEntry(
        string $tenantId,
        string $dailyBookEntryId,
        string $postingDate
    ): RuleResolutionResult {
        // Load entry (tenant-scoped)
        $entry = DailyBookEntry::where('id', $dailyBookEntryId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        // Find the mapping row for tenant where:
        // effective_from <= postingDate AND (effective_to is null OR effective_to >= postingDate)
        // If multiple match, choose the one with greatest effective_from (most recent)
        $postingDateObj = Carbon::parse($postingDate);
        
        $mapping = DailyBookAccountMapping::where('tenant_id', $tenantId)
            ->where('effective_from', '<=', $postingDateObj->format('Y-m-d'))
            ->where(function ($query) use ($postingDateObj) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $postingDateObj->format('Y-m-d'));
            })
            ->orderBy('effective_from', 'desc')
            ->firstOrFail();

        // Load accounts for snapshot
        $expenseDebitAccount = Account::findOrFail($mapping->expense_debit_account_id);
        $expenseCreditAccount = Account::findOrFail($mapping->expense_credit_account_id);
        $incomeDebitAccount = Account::findOrFail($mapping->income_debit_account_id);
        $incomeCreditAccount = Account::findOrFail($mapping->income_credit_account_id);

        // Build canonical snapshot (stable for hashing)
        $snapshot = [
            'source_type' => 'DAILY_BOOK_ENTRY',
            'source_id' => $entry->id,
            'posting_date' => $postingDateObj->format('Y-m-d'),
            'mapping' => [
                'version' => $mapping->version,
                'effective_from' => $mapping->effective_from->format('Y-m-d'),
                'effective_to' => $mapping->effective_to ? $mapping->effective_to->format('Y-m-d') : null,
                'expense_debit_account_code' => $expenseDebitAccount->code,
                'expense_credit_account_code' => $expenseCreditAccount->code,
                'income_debit_account_code' => $incomeDebitAccount->code,
                'income_credit_account_code' => $incomeCreditAccount->code,
            ],
        ];

        // Compute hash (stable key ordering)
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ruleHash = hash('sha256', $snapshotJson);

        // Determine accounts based on entry type
        if ($entry->type === 'EXPENSE') {
            $debitAccountId = $mapping->expense_debit_account_id;
            $creditAccountId = $mapping->expense_credit_account_id;
        } else {
            // INCOME
            $debitAccountId = $mapping->income_debit_account_id;
            $creditAccountId = $mapping->income_credit_account_id;
        }

        // Build allocation rows plan
        $costType = $entry->type === 'EXPENSE' ? 'DAILY_BOOK_EXPENSE' : 'DAILY_BOOK_INCOME';
        $allocationRows = [
            [
                'project_id' => $entry->project_id,
                'cost_type' => $costType,
                'amount' => $entry->gross_amount,
                'currency_code' => $entry->currency_code,
            ],
        ];

        // Build ledger entries plan (two entries that balance)
        $ledgerEntries = [
            [
                'account_id' => $debitAccountId,
                'debit' => $entry->gross_amount,
                'credit' => 0,
                'currency_code' => $entry->currency_code,
            ],
            [
                'account_id' => $creditAccountId,
                'debit' => 0,
                'credit' => $entry->gross_amount,
                'currency_code' => $entry->currency_code,
            ],
        ];

        return new RuleResolutionResult(
            ruleVersion: $mapping->version,
            ruleHash: $ruleHash,
            ruleSnapshot: $snapshot,
            allocationRows: $allocationRows,
            ledgerEntries: $ledgerEntries,
        );
    }
}
