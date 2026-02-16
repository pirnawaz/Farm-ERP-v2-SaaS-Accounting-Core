<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationClear;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Bank reconciliation: metadata only; no ledger mutation.
 * Clears stored in bank_reconciliation_clears; reversed PGs cannot be cleared.
 */
class BankReconciliationService
{
    public function __construct(
        private SystemAccountService $accountService,
        private BankStatementService $bankStatementService
    ) {}

    public function create(
        string $tenantId,
        string $accountCode,
        string $statementDate,
        string $statementBalance,
        ?string $notes = null,
        ?string $createdBy = null
    ): BankReconciliation {
        $allowed = ['BANK', 'CASH'];
        if (!in_array($accountCode, $allowed)) {
            throw new \InvalidArgumentException('account_code must be BANK or CASH.');
        }
        $account = $this->accountService->getByCode($tenantId, $accountCode);

        return BankReconciliation::create([
            'tenant_id' => $tenantId,
            'account_id' => $account->id,
            'statement_date' => $statementDate,
            'statement_balance' => $statementBalance,
            'status' => BankReconciliation::STATUS_DRAFT,
            'notes' => $notes,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, BankReconciliation>
     */
    public function list(string $tenantId, ?string $accountCode = null, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = BankReconciliation::forTenant($tenantId)
            ->with('account:id,code,name')
            ->orderByDesc('statement_date')
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($accountCode !== null && $accountCode !== '') {
            $query->whereHas('account', fn ($q) => $q->where('code', $accountCode));
        }

        return $query->get();
    }

    /**
     * Full report: book_balance, cleared_balance, uncleared lists, difference.
     */
    public function getReport(string $tenantId, string $reconciliationId): array
    {
        $rec = BankReconciliation::where('id', $reconciliationId)
            ->where('tenant_id', $tenantId)
            ->with('account:id,code,name')
            ->firstOrFail();

        $accountId = $rec->account_id;
        $statementDate = $rec->statement_date->format('Y-m-d');
        $statementBalance = (float) $rec->statement_balance;

        $bookBalance = $this->computeBookBalance($tenantId, $accountId, $statementDate);
        $clearedBalance = $this->computeClearedBalance($tenantId, $reconciliationId, $statementDate);
        $unclearedNet = $bookBalance - $clearedBalance;
        $difference = $statementBalance - $bookBalance;

        $uncleared = $this->getUnclearedEntries($tenantId, $accountId, $statementDate, $reconciliationId);
        $unclearedDebits = $uncleared['debits'];
        $unclearedCredits = $uncleared['credits'];

        $clearedCount = BankReconciliationClear::where('bank_reconciliation_id', $reconciliationId)
            ->where('status', BankReconciliationClear::STATUS_CLEARED)
            ->count();
        $unclearedCount = count($unclearedDebits) + count($unclearedCredits);

        $clearedEntries = $this->getClearedEntries($tenantId, $reconciliationId);

        $statementSummary = $this->bankStatementService->getStatementSummary($tenantId, $reconciliationId);
        $statementLines = $this->bankStatementService->getStatementLinesForReport($tenantId, $reconciliationId);

        return [
            'id' => $rec->id,
            'account_id' => $rec->account_id,
            'account_code' => $rec->account->code,
            'account_name' => $rec->account->name,
            'statement_date' => $statementDate,
            'statement_balance' => round($statementBalance, 2),
            'book_balance' => round($bookBalance, 2),
            'cleared_balance' => round($clearedBalance, 2),
            'uncleared_net' => round($unclearedNet, 2),
            'difference' => round($difference, 2),
            'status' => $rec->status,
            'notes' => $rec->notes,
            'finalized_at' => $rec->finalized_at?->toIso8601String(),
            'cleared_counts' => ['cleared' => $clearedCount, 'uncleared' => $unclearedCount],
            'uncleared_debits' => $unclearedDebits,
            'uncleared_credits' => $unclearedCredits,
            'cleared_entries' => $clearedEntries,
            'statement' => $statementSummary,
            'statement_lines' => $statementLines,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Cleared entries for this reconciliation (CLEARED status only).
     */
    private function getClearedEntries(string $tenantId, string $bankReconciliationId): array
    {
        $rows = DB::select("
            SELECT c.ledger_entry_id, c.cleared_date,
                   pg.posting_date, le.debit_amount, le.credit_amount,
                   le.posting_group_id, pg.source_type, pg.source_id
            FROM bank_reconciliation_clears c
            JOIN ledger_entries le ON le.id = c.ledger_entry_id AND le.tenant_id = c.tenant_id
            JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            WHERE c.tenant_id = :tenant_id
              AND c.bank_reconciliation_id = :rec_id
              AND c.status = 'CLEARED'
            ORDER BY c.cleared_date ASC, c.ledger_entry_id ASC
        ", [
            'tenant_id' => $tenantId,
            'rec_id' => $bankReconciliationId,
        ]);

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'ledger_entry_id' => $r->ledger_entry_id,
                'posting_date' => $r->posting_date,
                'description' => $r->source_type . '#' . ($r->source_id ?? ''),
                'debit_amount' => (float) $r->debit_amount,
                'credit_amount' => (float) $r->credit_amount,
                'posting_group_id' => $r->posting_group_id,
                'cleared_date' => $r->cleared_date,
            ];
        }
        return $result;
    }

    /**
     * Book balance: SUM(debit - credit) for account, posting_date <= statement_date, non-reversed.
     */
    public function computeBookBalance(string $tenantId, string $accountId, string $asOfDate): float
    {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
            FROM ledger_entries le
            JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            WHERE le.tenant_id = :tenant_id
              AND le.account_id = :account_id
              AND pg.posting_date <= :as_of
              AND NOT EXISTS (
                SELECT 1 FROM posting_groups pg_rev
                WHERE pg_rev.reversal_of_posting_group_id = pg.id
              )
        ", [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'as_of' => $asOfDate,
        ]);
        return (float) ($row->net ?? 0);
    }

    /**
     * Cleared balance: SUM(debit - credit) for entries that have CLEARED clear in this reconciliation.
     */
    public function computeClearedBalance(string $tenantId, string $bankReconciliationId, string $asOfDate): float
    {
        $row = DB::selectOne("
            SELECT COALESCE(SUM(le.debit_amount - le.credit_amount), 0) AS net
            FROM bank_reconciliation_clears c
            JOIN ledger_entries le ON le.id = c.ledger_entry_id AND le.tenant_id = c.tenant_id
            WHERE c.tenant_id = :tenant_id
              AND c.bank_reconciliation_id = :rec_id
              AND c.status = 'CLEARED'
              AND c.cleared_date <= :as_of
        ", [
            'tenant_id' => $tenantId,
            'rec_id' => $bankReconciliationId,
            'as_of' => $asOfDate,
        ]);
        return (float) ($row->net ?? 0);
    }

    /**
     * Uncleared entries: ledger entries on account, posting_date <= statement_date, non-reversed,
     * and not cleared (no ACTIVE clear in this reconciliation).
     */
    public function getUnclearedEntries(string $tenantId, string $accountId, string $statementDate, string $bankReconciliationId): array
    {
        $clearedEntryIds = BankReconciliationClear::where('bank_reconciliation_id', $bankReconciliationId)
            ->where('status', BankReconciliationClear::STATUS_CLEARED)
            ->pluck('ledger_entry_id')
            ->all();

        $entries = DB::select("
            SELECT le.id AS ledger_entry_id, pg.posting_date, le.debit_amount, le.credit_amount,
                   le.posting_group_id, pg.source_type, pg.source_id
            FROM ledger_entries le
            JOIN posting_groups pg ON pg.id = le.posting_group_id AND pg.tenant_id = le.tenant_id
            WHERE le.tenant_id = :tenant_id
              AND le.account_id = :account_id
              AND pg.posting_date <= :statement_date
              AND NOT EXISTS (
                SELECT 1 FROM posting_groups pg_rev
                WHERE pg_rev.reversal_of_posting_group_id = pg.id
              )
            ORDER BY pg.posting_date ASC, le.id ASC
        ", [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'statement_date' => $statementDate,
        ]);

        $debits = [];
        $credits = [];
        foreach ($entries as $e) {
            if (in_array($e->ledger_entry_id, $clearedEntryIds)) {
                continue;
            }
            $item = [
                'ledger_entry_id' => $e->ledger_entry_id,
                'posting_date' => $e->posting_date,
                'description' => $e->source_type . '#' . ($e->source_id ?? ''),
                'debit_amount' => (float) $e->debit_amount,
                'credit_amount' => (float) $e->credit_amount,
                'posting_group_id' => $e->posting_group_id,
            ];
            if ((float) $e->debit_amount > 0) {
                $debits[] = $item;
            }
            if ((float) $e->credit_amount > 0) {
                $credits[] = $item;
            }
        }
        return ['debits' => $debits, 'credits' => $credits];
    }

    /**
     * Clear ledger entries. Validates account, posting_date <= statement_date, not reversed, not already CLEARED.
     */
    public function clear(
        string $tenantId,
        string $reconciliationId,
        array $ledgerEntryIds,
        ?string $clearedDate = null,
        ?string $createdBy = null
    ): array {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.');
        }

        $statementDate = $rec->statement_date->format('Y-m-d');
        $clearedDate = $clearedDate ?: $statementDate;
        if ($clearedDate > $statementDate) {
            throw new \InvalidArgumentException('cleared_date cannot be after statement_date.');
        }

        $created = [];
        foreach ($ledgerEntryIds as $entryId) {
            $entry = LedgerEntry::where('id', $entryId)->where('tenant_id', $tenantId)->firstOrFail();
            if ($entry->account_id !== $rec->account_id) {
                throw new \InvalidArgumentException("Ledger entry {$entryId} is not for this reconciliation account.");
            }

            $pg = PostingGroup::where('id', $entry->posting_group_id)->where('tenant_id', $tenantId)->firstOrFail();
            if ($pg->posting_date->format('Y-m-d') > $statementDate) {
                throw new \InvalidArgumentException("Ledger entry {$entryId} posting_date is after statement_date.");
            }
            if (PostingGroup::where('reversal_of_posting_group_id', $pg->id)->exists()) {
                throw new \InvalidArgumentException("Ledger entry {$entryId} belongs to a reversed posting group.");
            }

            $alreadyCleared = BankReconciliationClear::where('ledger_entry_id', $entryId)
                ->where('status', BankReconciliationClear::STATUS_CLEARED)
                ->exists();
            if ($alreadyCleared) {
                throw new \InvalidArgumentException("Ledger entry {$entryId} is already cleared in another reconciliation.");
            }

            $clear = BankReconciliationClear::create([
                'tenant_id' => $tenantId,
                'bank_reconciliation_id' => $reconciliationId,
                'ledger_entry_id' => $entryId,
                'cleared_date' => $clearedDate,
                'status' => BankReconciliationClear::STATUS_CLEARED,
                'created_by' => $createdBy,
            ]);
            $created[] = $clear->id;
        }

        return ['cleared' => $created];
    }

    /**
     * Unclear: void CLEARED rows (auditable).
     */
    public function unclear(
        string $tenantId,
        string $reconciliationId,
        array $ledgerEntryIds,
        ?string $reason = null,
        ?string $voidedBy = null
    ): array {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.');
        }

        $voided = BankReconciliationClear::where('tenant_id', $tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->whereIn('ledger_entry_id', $ledgerEntryIds)
            ->where('status', BankReconciliationClear::STATUS_CLEARED)
            ->update([
                'status' => BankReconciliationClear::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
            ]);

        return ['voided' => $voided];
    }

    public function finalize(string $tenantId, string $reconciliationId, ?string $finalizedBy = null): BankReconciliation
    {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be finalized.');
        }

        $rec->update([
            'status' => BankReconciliation::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by' => $finalizedBy,
        ]);
        return $rec->fresh();
    }
}
