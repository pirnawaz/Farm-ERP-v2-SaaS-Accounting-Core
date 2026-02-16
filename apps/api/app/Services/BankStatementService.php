<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\BankStatementMatch;
use App\Models\LedgerEntry;
use App\Models\PostingGroup;
use Illuminate\Support\Facades\DB;

/**
 * Statement lines and matches for bank reconciliation. Metadata only; no ledger mutation.
 */
class BankStatementService
{
    public function addStatementLine(
        string $tenantId,
        string $reconciliationId,
        string $lineDate,
        string $amount,
        ?string $description = null,
        ?string $reference = null,
        ?string $createdBy = null
    ): BankStatementLine {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.', 409);
        }

        $statementDate = $rec->statement_date->format('Y-m-d');
        if ($lineDate > $statementDate) {
            throw new \InvalidArgumentException('line_date cannot be after statement_date.', 422);
        }

        $amountFloat = (float) $amount;
        // Allow zero for adjustments; deposits > 0, withdrawals < 0
        // No strict sign rule beyond "signed" convention

        return BankStatementLine::create([
            'tenant_id' => $tenantId,
            'bank_reconciliation_id' => $reconciliationId,
            'line_date' => $lineDate,
            'amount' => $amountFloat,
            'description' => $description,
            'reference' => $reference,
            'status' => BankStatementLine::STATUS_ACTIVE,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, BankStatementLine>
     */
    public function listStatementLines(string $tenantId, string $reconciliationId, bool $includeVoided = false): \Illuminate\Database\Eloquent\Collection
    {
        $this->ensureReconciliationBelongsToTenant($tenantId, $reconciliationId);

        $query = BankStatementLine::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->orderBy('line_date')
            ->orderBy('created_at');

        if (!$includeVoided) {
            $query->active();
        }

        return $query->get();
    }

    public function voidStatementLine(
        string $tenantId,
        string $reconciliationId,
        string $lineId,
        ?string $reason = null,
        ?string $voidedBy = null
    ): BankStatementLine {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.', 409);
        }

        $line = BankStatementLine::where('id', $lineId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($line->status !== BankStatementLine::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Statement line is not ACTIVE.', 422);
        }

        // Void any active match first (audit: match becomes VOID)
        BankStatementMatch::where('bank_statement_line_id', $lineId)
            ->where('status', BankStatementMatch::STATUS_MATCHED)
            ->update([
                'status' => BankStatementMatch::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
            ]);

        $line->update([
            'status' => BankStatementLine::STATUS_VOID,
            'voided_at' => now(),
            'voided_by' => $voidedBy,
        ]);

        return $line->fresh();
    }

    public function matchStatementLine(
        string $tenantId,
        string $reconciliationId,
        string $lineId,
        string $ledgerEntryId,
        ?string $createdBy = null
    ): BankStatementMatch {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.', 409);
        }

        $line = BankStatementLine::where('id', $lineId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($line->status !== BankStatementLine::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Statement line is not ACTIVE.', 422);
        }

        if (BankStatementMatch::where('bank_statement_line_id', $lineId)->where('status', BankStatementMatch::STATUS_MATCHED)->exists()) {
            throw new \InvalidArgumentException('Statement line already has an active match.', 409);
        }

        if (BankStatementMatch::where('ledger_entry_id', $ledgerEntryId)->where('status', BankStatementMatch::STATUS_MATCHED)->exists()) {
            throw new \InvalidArgumentException('Ledger entry is already matched to another statement line.', 409);
        }

        $entry = LedgerEntry::where('id', $ledgerEntryId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($entry->account_id !== $rec->account_id) {
            throw new \InvalidArgumentException('Ledger entry is not for this reconciliation account.', 409);
        }

        $pg = PostingGroup::where('id', $entry->posting_group_id)->where('tenant_id', $tenantId)->firstOrFail();
        $statementDate = $rec->statement_date->format('Y-m-d');
        if ($pg->posting_date->format('Y-m-d') > $statementDate) {
            throw new \InvalidArgumentException('Ledger entry posting_date is after statement_date.', 409);
        }
        if (PostingGroup::where('reversal_of_posting_group_id', $pg->id)->exists()) {
            throw new \InvalidArgumentException('Ledger entry belongs to a reversed posting group.', 409);
        }

        // Sign consistency: statement amount > 0 (deposit) -> ledger debit; amount < 0 (withdrawal) -> ledger credit
        $lineAmount = (float) $line->amount;
        $hasDebit = (float) $entry->debit_amount > 0;
        $hasCredit = (float) $entry->credit_amount > 0;
        if ($lineAmount > 0 && !$hasDebit) {
            throw new \InvalidArgumentException('Statement deposit should match a ledger debit entry.', 409);
        }
        if ($lineAmount < 0 && !$hasCredit) {
            throw new \InvalidArgumentException('Statement withdrawal should match a ledger credit entry.', 409);
        }

        return BankStatementMatch::create([
            'tenant_id' => $tenantId,
            'bank_reconciliation_id' => $reconciliationId,
            'bank_statement_line_id' => $lineId,
            'ledger_entry_id' => $ledgerEntryId,
            'status' => BankStatementMatch::STATUS_MATCHED,
            'created_by' => $createdBy,
        ]);
    }

    public function unmatchStatementLine(
        string $tenantId,
        string $reconciliationId,
        string $lineId,
        ?string $reason = null,
        ?string $voidedBy = null
    ): int {
        $rec = BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
        if ($rec->status !== BankReconciliation::STATUS_DRAFT) {
            throw new \InvalidArgumentException('Only DRAFT reconciliations can be modified.', 409);
        }

        $line = BankStatementLine::where('id', $lineId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $updated = BankStatementMatch::where('tenant_id', $tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->where('bank_statement_line_id', $lineId)
            ->where('status', BankStatementMatch::STATUS_MATCHED)
            ->update([
                'status' => BankStatementMatch::STATUS_VOID,
                'voided_at' => now(),
                'voided_by' => $voidedBy,
            ]);

        return $updated;
    }

    /**
     * Summary for report: lines_total, matched_lines_total, unmatched_lines_total, matched_ledger_total, difference_vs_matched_ledger.
     */
    public function getStatementSummary(string $tenantId, string $reconciliationId): array
    {
        $this->ensureReconciliationBelongsToTenant($tenantId, $reconciliationId);

        $linesTotal = (float) BankStatementLine::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->active()
            ->sum('amount');

        $matchedLineIds = BankStatementMatch::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->matched()
            ->pluck('bank_statement_line_id')
            ->all();

        $matchedStatementTotal = (float) BankStatementLine::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->active()
            ->whereIn('id', $matchedLineIds)
            ->sum('amount');

        $unmatchedStatementTotal = $linesTotal - $matchedStatementTotal;

        $matchedLedgerEntryIds = BankStatementMatch::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->matched()
            ->pluck('ledger_entry_id')
            ->all();

        $matchedLedgerTotal = 0.0;
        if (!empty($matchedLedgerEntryIds)) {
            $matchedLedgerTotal = (float) LedgerEntry::where('tenant_id', $tenantId)
                ->whereIn('id', $matchedLedgerEntryIds)
                ->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) AS net')
                ->value('net');
        }

        $differenceVsMatchedLedger = $linesTotal - $matchedLedgerTotal;

        return [
            'lines_total' => round($linesTotal, 2),
            'matched_lines_total' => round($matchedStatementTotal, 2),
            'unmatched_lines_total' => round($unmatchedStatementTotal, 2),
            'matched_ledger_total' => round($matchedLedgerTotal, 2),
            'difference_vs_matched_ledger' => round($differenceVsMatchedLedger, 2),
        ];
    }

    /**
     * List ACTIVE statement lines with match info for report.
     */
    public function getStatementLinesForReport(string $tenantId, string $reconciliationId): array
    {
        $this->ensureReconciliationBelongsToTenant($tenantId, $reconciliationId);

        $lines = BankStatementLine::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->active()
            ->orderBy('line_date')
            ->orderBy('created_at')
            ->get();

        $matchesByLine = BankStatementMatch::forTenant($tenantId)
            ->where('bank_reconciliation_id', $reconciliationId)
            ->matched()
            ->with('ledgerEntry.postingGroup')
            ->get()
            ->keyBy('bank_statement_line_id');

        $result = [];
        foreach ($lines as $line) {
            $match = $matchesByLine->get($line->id);
            $matchedLedgerEntryId = null;
            $matchedPostingDate = null;
            $matchedAmount = null;
            if ($match && $match->ledgerEntry) {
                $le = $match->ledgerEntry;
                $matchedLedgerEntryId = $le->id;
                if ($le->postingGroup) {
                    $matchedPostingDate = $le->postingGroup->posting_date->format('Y-m-d');
                }
                $matchedAmount = (float) $le->debit_amount - (float) $le->credit_amount;
            }
            $result[] = [
                'id' => $line->id,
                'line_date' => $line->line_date->format('Y-m-d'),
                'amount' => round((float) $line->amount, 2),
                'description' => $line->description,
                'reference' => $line->reference,
                'is_matched' => $match !== null,
                'matched_ledger_entry_id' => $matchedLedgerEntryId,
                'matched_posting_date' => $matchedPostingDate,
                'matched_amount' => $matchedAmount !== null ? round($matchedAmount, 2) : null,
            ];
        }
        return $result;
    }

    private function ensureReconciliationBelongsToTenant(string $tenantId, string $reconciliationId): void
    {
        BankReconciliation::where('id', $reconciliationId)->where('tenant_id', $tenantId)->firstOrFail();
    }
}
