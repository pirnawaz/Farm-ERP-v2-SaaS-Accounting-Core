<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use App\Services\Accounting\PostValidationService;
use App\Services\PostingDateGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class JournalEntryService
{
    public function __construct(
        private PostValidationService $postValidationService,
        private ReversalService $reversalService,
        private PostingDateGuard $postingDateGuard
    ) {}

    /**
     * Create a draft journal entry (header + lines). Lines optional on create; required for post.
     */
    public function createDraft(
        string $tenantId,
        string $entryDate,
        ?string $memo = null,
        array $lines = [],
        ?string $createdBy = null
    ): JournalEntry {
        return DB::transaction(function () use ($tenantId, $entryDate, $memo, $lines, $createdBy) {
            $journalNumber = $this->generateJournalNumber($tenantId);
            $journal = JournalEntry::create([
                'tenant_id' => $tenantId,
                'journal_number' => $journalNumber,
                'entry_date' => $entryDate,
                'memo' => $memo,
                'status' => JournalEntry::STATUS_DRAFT,
                'created_by' => $createdBy,
            ]);
            $this->replaceLines($journal, $lines, $tenantId);
            return $journal->fresh('lines.account');
        });
    }

    /**
     * Update draft journal (header + replace lines). Only allowed when status is DRAFT.
     */
    public function updateDraft(
        string $journalId,
        string $tenantId,
        array $data,
        array $lines
    ): JournalEntry {
        $journal = JournalEntry::where('id', $journalId)
            ->where('tenant_id', $tenantId)
            ->where('status', JournalEntry::STATUS_DRAFT)
            ->firstOrFail();

        return DB::transaction(function () use ($journal, $data, $lines, $tenantId) {
            $journal->update(array_intersect_key($data, array_flip([
                'entry_date', 'memo',
            ])));
            $this->replaceLines($journal, $lines, $tenantId);
            return $journal->fresh('lines.account');
        });
    }

    /**
     * Post journal: create posting_group + ledger_entries, set status POSTED.
     * Validates: DRAFT, balanced (decimal-safe), >=2 lines, entry_date, accounts tenant-scoped and not deprecated.
     */
    public function postJournal(string $journalId, string $tenantId, ?string $postedBy = null): PostingGroup
    {
        return DB::transaction(function () use ($journalId, $tenantId, $postedBy) {
            $journal = JournalEntry::where('id', $journalId)
                ->where('tenant_id', $tenantId)
                ->with('lines.account')
                ->firstOrFail();

            if ($journal->status !== JournalEntry::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only DRAFT journals can be posted.', 422);
            }

            $lines = $journal->lines;
            if ($lines->count() < 2) {
                throw new InvalidArgumentException('Journal must have at least 2 lines to post.', 422);
            }

            $entryDate = $journal->entry_date;
            if (!$entryDate) {
                throw new InvalidArgumentException('Entry date is required to post.', 422);
            }
            $postingDateStr = Carbon::parse($entryDate)->format('Y-m-d');

            // Decimal-safe balance check
            $totalDebit = '0';
            $totalCredit = '0';
            foreach ($lines as $line) {
                $totalDebit = bcadd($totalDebit, (string) $line->debit_amount, 2);
                $totalCredit = bcadd($totalCredit, (string) $line->credit_amount, 2);
            }
            if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                throw new InvalidArgumentException('Journal is not balanced: debits must equal credits.', 422);
            }

            // Idempotency: one posting group per journal
            $existing = PostingGroup::where('tenant_id', $tenantId)
                ->where('source_type', 'JOURNAL_ENTRY')
                ->where('source_id', $journal->id)
                ->first();
            if ($existing) {
                $journal->update([
                    'status' => JournalEntry::STATUS_POSTED,
                    'posting_group_id' => $existing->id,
                    'posted_at' => now(),
                    'posted_by' => $postedBy,
                ]);
                return $existing->load(['ledgerEntries.account']);
            }

            // Validate accounts: tenant-scoped and not deprecated
            $ledgerLines = $lines->map(fn ($l) => ['account_id' => $l->account_id])->all();
            foreach ($lines as $line) {
                Account::where('id', $line->account_id)->where('tenant_id', $tenantId)->firstOrFail();
            }
            $this->postValidationService->validateNoDeprecatedAccounts($tenantId, $ledgerLines);

            $this->postingDateGuard->assertPostingDateAllowed($tenantId, Carbon::parse($postingDateStr));

            $postingGroup = PostingGroup::create([
                'tenant_id' => $tenantId,
                'crop_cycle_id' => null,
                'source_type' => 'JOURNAL_ENTRY',
                'source_id' => $journal->id,
                'posting_date' => $postingDateStr,
                'idempotency_key' => 'journal:' . $journal->id,
            ]);

            foreach ($lines as $line) {
                LedgerEntry::create([
                    'tenant_id' => $tenantId,
                    'posting_group_id' => $postingGroup->id,
                    'account_id' => $line->account_id,
                    'debit_amount' => $line->debit_amount,
                    'credit_amount' => $line->credit_amount,
                    'currency_code' => 'GBP',
                ]);
            }

            $journal->update([
                'status' => JournalEntry::STATUS_POSTED,
                'posting_group_id' => $postingGroup->id,
                'posted_at' => now(),
                'posted_by' => $postedBy,
            ]);

            return $postingGroup->fresh(['ledgerEntries.account']);
        });
    }

    /**
     * Reverse journal: create reversal posting_group (opposite entries), mark journal REVERSED.
     */
    public function reverseJournal(
        string $journalId,
        string $tenantId,
        ?string $reversalDate = null,
        ?string $memo = null
    ): PostingGroup {
        $journal = JournalEntry::where('id', $journalId)
            ->where('tenant_id', $tenantId)
            ->with('postingGroup.ledgerEntries')
            ->firstOrFail();

        if ($journal->status !== JournalEntry::STATUS_POSTED) {
            throw new InvalidArgumentException('Only POSTED journals can be reversed.', 422);
        }
        if ($journal->reversal_posting_group_id !== null) {
            throw new InvalidArgumentException('Journal is already reversed.', 422);
        }

        $postingDate = $reversalDate
            ? Carbon::parse($reversalDate)->format('Y-m-d')
            : $journal->entry_date->format('Y-m-d');

        $reason = $memo ?? ('Reversal of journal ' . $journal->journal_number);

        $reversalPg = $this->reversalService->reversePostingGroup(
            $journal->posting_group_id,
            $tenantId,
            $postingDate,
            $reason
        );

        $journal->update([
            'status' => JournalEntry::STATUS_REVERSED,
            'reversed_at' => now(),
            'reversal_posting_group_id' => $reversalPg->id,
        ]);

        return $reversalPg->load(['ledgerEntries.account']);
    }

    private function replaceLines(JournalEntry $journal, array $lines, string $tenantId): void
    {
        $journal->lines()->delete();
        foreach ($lines as $line) {
            $accountId = $line['account_id'] ?? null;
            if ($accountId) {
                Account::where('id', $accountId)->where('tenant_id', $tenantId)->firstOrFail();
            }
            JournalEntryLine::create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journal->id,
                'account_id' => $accountId,
                'description' => $line['description'] ?? null,
                'debit_amount' => $line['debit_amount'] ?? 0,
                'credit_amount' => $line['credit_amount'] ?? 0,
            ]);
        }
    }

    private function generateJournalNumber(string $tenantId): string
    {
        $last = JournalEntry::where('tenant_id', $tenantId)
            ->whereNotNull('journal_number')
            ->where('journal_number', 'like', 'JE-%')
            ->orderByRaw('LENGTH(journal_number) DESC, journal_number DESC')
            ->first();

        $next = 1;
        if ($last && preg_match('/^JE-(\d+)$/', $last->journal_number, $m)) {
            $next = (int) $m[1] + 1;
        }
        return 'JE-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
