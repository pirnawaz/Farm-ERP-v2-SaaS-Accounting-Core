import { useState, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import {
  useBankReconciliation,
  useClearBankEntries,
  useUnclearBankEntries,
  useFinalizeBankReconciliation,
  useAddStatementLine,
  useVoidStatementLine,
  useMatchStatementLine,
  useUnmatchStatementLine,
} from '../hooks/useBankReconciliations';
import { PageHeader } from '../components/PageHeader';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { FormField } from '../components/FormField';
import { useFormatting } from '../hooks/useFormatting';
import type {
  BankReconciliationLedgerEntryItem,
  BankReconciliationStatementLineItem,
  BankReconciliationStatementSummary,
} from '../types';

export default function BankReconciliationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { data: report, isLoading, error } = useBankReconciliation(id);
  const clearMutation = useClearBankEntries(id ?? '');
  const unclearMutation = useUnclearBankEntries(id ?? '');
  const finalizeMutation = useFinalizeBankReconciliation(id ?? '');
  const addStatementLineMutation = useAddStatementLine(id ?? '');
  const voidStatementLineMutation = useVoidStatementLine(id ?? '');
  const matchStatementLineMutation = useMatchStatementLine(id ?? '');
  const unmatchStatementLineMutation = useUnmatchStatementLine(id ?? '');

  const [selectedUncleared, setSelectedUncleared] = useState<Set<string>>(new Set());
  const [selectedCleared, setSelectedCleared] = useState<Set<string>>(new Set());
  const [confirmAction, setConfirmAction] = useState<
    'clear' | 'unclear' | 'finalize' | null
  >(null);
  const [showAddLineModal, setShowAddLineModal] = useState(false);
  const [addLineForm, setAddLineForm] = useState({
    line_date: new Date().toISOString().split('T')[0],
    amount: '' as string | number,
    description: '',
    reference: '',
  });
  const [matchModalLine, setMatchModalLine] = useState<BankReconciliationStatementLineItem | null>(null);
  const [voidLineId, setVoidLineId] = useState<string | null>(null);
  const [unmatchLineId, setUnmatchLineId] = useState<string | null>(null);

  const isDraft = report?.status === 'DRAFT';

  const toggleUncleared = (ledgerEntryId: string) => {
    setSelectedUncleared((prev) => {
      const next = new Set(prev);
      if (next.has(ledgerEntryId)) next.delete(ledgerEntryId);
      else next.add(ledgerEntryId);
      return next;
    });
  };

  const toggleCleared = (ledgerEntryId: string) => {
    setSelectedCleared((prev) => {
      const next = new Set(prev);
      if (next.has(ledgerEntryId)) next.delete(ledgerEntryId);
      else next.add(ledgerEntryId);
      return next;
    });
  };

  const clearedEntries = report?.cleared_entries ?? [];
  const statementSummary: BankReconciliationStatementSummary | null = report?.statement ?? null;
  const statementLines: BankReconciliationStatementLineItem[] = report?.statement_lines ?? [];

  const eligibleEntriesForMatch = useMemo(() => {
    if (!report || !matchModalLine) return [];
    const amount = matchModalLine.amount;
    const debits = report.uncleared_debits || [];
    const credits = report.uncleared_credits || [];
    const cleared = report.cleared_entries || [];
    const matchedIds = new Set(
      statementLines.filter((l) => l.is_matched && l.matched_ledger_entry_id).map((l) => l.matched_ledger_entry_id!)
    );
    const filterById = (arr: BankReconciliationLedgerEntryItem[]) =>
      arr.filter((e) => !matchedIds.has(e.ledger_entry_id));
    if (amount > 0) {
      return [...filterById(debits), ...filterById(cleared).filter((e) => e.debit_amount > 0)];
    }
    if (amount < 0) {
      return [...filterById(credits), ...filterById(cleared).filter((e) => e.credit_amount > 0)];
    }
    return [];
  }, [report, matchModalLine, statementLines]);

  const selectedUnclearedIds = Array.from(selectedUncleared);
  const selectedClearedIds = Array.from(selectedCleared);

  const handleClear = async () => {
    if (selectedUnclearedIds.length === 0) return;
    await clearMutation.mutateAsync({
      ledger_entry_ids: selectedUnclearedIds,
    });
    setSelectedUncleared(new Set());
    setConfirmAction(null);
  };

  const handleUnclear = async () => {
    if (selectedClearedIds.length === 0) return;
    await unclearMutation.mutateAsync({
      ledger_entry_ids: selectedClearedIds,
    });
    setSelectedCleared(new Set());
    setConfirmAction(null);
  };

  const handleFinalize = async () => {
    await finalizeMutation.mutateAsync();
    setConfirmAction(null);
  };

  const handleAddStatementLine = async () => {
    const num = typeof addLineForm.amount === 'string' ? parseFloat(addLineForm.amount) : addLineForm.amount;
    if (Number.isNaN(num)) return;
    await addStatementLineMutation.mutateAsync({
      line_date: addLineForm.line_date,
      amount: num,
      description: addLineForm.description?.trim() || undefined,
      reference: addLineForm.reference?.trim() || undefined,
    });
    setShowAddLineModal(false);
    setAddLineForm({
      line_date: new Date().toISOString().split('T')[0],
      amount: '',
      description: '',
      reference: '',
    });
  };

  const handleVoidLine = async () => {
    if (!voidLineId) return;
    await voidStatementLineMutation.mutateAsync(voidLineId);
    setVoidLineId(null);
  };

  const handleMatch = async (ledgerEntryId: string) => {
    if (!matchModalLine) return;
    await matchStatementLineMutation.mutateAsync({
      lineId: matchModalLine.id,
      ledger_entry_id: ledgerEntryId,
    });
    setMatchModalLine(null);
  };

  const handleUnmatch = async () => {
    if (!unmatchLineId) return;
    await unmatchStatementLineMutation.mutateAsync(unmatchLineId);
    setUnmatchLineId(null);
  };

  if (isLoading || !id) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error || !report) {
    return (
      <div className="p-6">
        <PageHeader
          title="Bank Reconciliation"
          backTo="/app/reports/bank-reconciliation"
          breadcrumbs={[
            { label: 'Reports', to: '/app/reports' },
            { label: 'Bank Reconciliation', to: '/app/reports/bank-reconciliation' },
            { label: 'Detail' },
          ]}
        />
        <p className="text-red-600">
          {error instanceof Error ? error.message : 'Reconciliation not found.'}
        </p>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title={`Bank Reconciliation — ${report.account_code}`}
        backTo="/app/reports/bank-reconciliation"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'Bank Reconciliation', to: '/app/reports/bank-reconciliation' },
          { label: report.statement_date },
        ]}
        right={
          isDraft ? (
            <button
              type="button"
              onClick={() => setConfirmAction('finalize')}
              disabled={finalizeMutation.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              Finalize
            </button>
          ) : null
        }
      />

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <span className="inline-flex items-center px-2.5 py-0.5 rounded text-sm font-medium bg-[#E6ECEA] text-[#1F6F5C]">
          {report.account_code}
        </span>
        <span
          className={`inline-flex items-center px-2.5 py-0.5 rounded text-sm font-medium ${
            report.status === 'FINALIZED'
              ? 'bg-green-100 text-green-800'
              : report.status === 'DRAFT'
                ? 'bg-amber-100 text-amber-800'
                : 'bg-gray-100 text-gray-800'
          }`}
        >
          {report.status}
        </span>
        <span className="text-sm text-gray-600">
          Statement date: <strong>{formatDate(report.statement_date)}</strong>
        </span>
        <span className="text-sm text-gray-600">
          Statement balance:{' '}
          <strong className="tabular-nums">{formatMoney(report.statement_balance)}</strong>
        </span>
        {report.finalized_at && (
          <span className="text-sm text-gray-500">
            Finalized at {formatDate(report.finalized_at)}
          </span>
        )}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
          <p className="text-sm text-gray-500">Book balance</p>
          <p className="text-lg font-semibold tabular-nums">
            {formatMoney(report.book_balance)}
          </p>
        </div>
        <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
          <p className="text-sm text-gray-500">Cleared balance</p>
          <p className="text-lg font-semibold tabular-nums">
            {formatMoney(report.cleared_balance)}
          </p>
        </div>
        <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
          <p className="text-sm text-gray-500">Uncleared net</p>
          <p className="text-lg font-semibold tabular-nums">
            {formatMoney(report.uncleared_net)}
          </p>
        </div>
        <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
          <p className="text-sm text-gray-500">Difference</p>
          <p
            className={`text-lg font-semibold tabular-nums ${
              report.difference === 0 ? 'text-green-600' : 'text-amber-600'
            }`}
          >
            {formatMoney(report.difference)}
          </p>
        </div>
      </div>

      {statementSummary != null && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
            <p className="text-sm text-gray-500">Statement total (lines)</p>
            <p className="text-lg font-semibold tabular-nums">
              {formatMoney(statementSummary.lines_total)}
            </p>
          </div>
          <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
            <p className="text-sm text-gray-500">Matched total</p>
            <p className="text-lg font-semibold tabular-nums">
              {formatMoney(statementSummary.matched_ledger_total)}
            </p>
          </div>
          <div className="bg-white rounded-lg shadow p-4 border border-gray-200">
            <p className="text-sm text-gray-500">Difference (Statement − Matched)</p>
            <p
              className={`text-lg font-semibold tabular-nums ${
                statementSummary.difference_vs_matched_ledger === 0 ? 'text-green-600' : 'text-amber-600'
              }`}
            >
              {formatMoney(statementSummary.difference_vs_matched_ledger)}
            </p>
          </div>
        </div>
      )}

      {isDraft && (selectedUnclearedIds.length > 0 || selectedClearedIds.length > 0) && (
        <div className="flex gap-2 mb-4">
          {selectedUnclearedIds.length > 0 && (
            <button
              type="button"
              onClick={() => setConfirmAction('clear')}
              disabled={clearMutation.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 text-sm"
            >
              Clear selected ({selectedUnclearedIds.length})
            </button>
          )}
          {selectedClearedIds.length > 0 && (
            <button
              type="button"
              onClick={() => setConfirmAction('unclear')}
              disabled={unclearMutation.isPending}
              className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50 text-sm"
            >
              Unclear selected ({selectedClearedIds.length})
            </button>
          )}
        </div>
      )}

      <div className="space-y-6">
        <section>
          <div className="flex justify-between items-center mb-2">
            <h2 className="text-lg font-medium text-gray-900">Statement lines</h2>
            {isDraft && (
              <button
                type="button"
                onClick={() => setShowAddLineModal(true)}
                className="px-3 py-1.5 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm"
              >
                Add line
              </button>
            )}
          </div>
          {statementLines.length === 0 ? (
            <div className="text-sm text-gray-500 py-4 bg-gray-50 rounded-lg px-4">
              No statement lines. Add lines manually to compare with the ledger.
            </div>
          ) : (
            <div className="overflow-x-auto rounded-lg border border-gray-200">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Description / Reference</th>
                    <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                    <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Status</th>
                    {isDraft && (
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">Actions</th>
                    )}
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {statementLines.map((line) => (
                    <tr key={line.id} className="hover:bg-gray-50">
                      <td className="px-4 py-2 text-sm text-gray-900 whitespace-nowrap">
                        {formatDate(line.line_date)}
                      </td>
                      <td className="px-4 py-2 text-sm text-gray-900">
                        {[line.description, line.reference].filter(Boolean).join(' · ') || '—'}
                      </td>
                      <td className="px-4 py-2 text-sm text-right tabular-nums">
                        {formatMoney(line.amount)}
                      </td>
                      <td className="px-4 py-2">
                        {line.is_matched ? (
                          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                            Matched
                          </span>
                        ) : (
                          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                            Unmatched
                          </span>
                        )}
                      </td>
                      {isDraft && (
                        <td className="px-4 py-2 text-sm space-x-2">
                          {line.is_matched ? (
                            <button
                              type="button"
                              onClick={() => setUnmatchLineId(line.id)}
                              className="text-amber-600 hover:text-amber-800"
                            >
                              Unmatch
                            </button>
                          ) : (
                            <button
                              type="button"
                              onClick={() => setMatchModalLine(line)}
                              className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                            >
                              Match to ledger
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => setVoidLineId(line.id)}
                            className="text-red-600 hover:text-red-800"
                          >
                            Void
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section>
          <h2 className="text-lg font-medium text-gray-900 mb-2">
            Uncleared deposits (debits)
          </h2>
          <EntriesTable
            entries={report.uncleared_debits || []}
            amountKey="debit_amount"
            selectedIds={selectedUncleared}
            onToggle={toggleUncleared}
            showCheckbox={isDraft}
            formatMoney={formatMoney}
            formatDate={formatDate}
          />
        </section>

        <section>
          <h2 className="text-lg font-medium text-gray-900 mb-2">
            Uncleared payments (credits)
          </h2>
          <EntriesTable
            entries={report.uncleared_credits || []}
            amountKey="credit_amount"
            selectedIds={selectedUncleared}
            onToggle={toggleUncleared}
            showCheckbox={isDraft}
            formatMoney={formatMoney}
            formatDate={formatDate}
          />
        </section>

        {clearedEntries.length > 0 && (
          <section>
            <h2 className="text-lg font-medium text-gray-900 mb-2">Cleared items</h2>
            <ClearedEntriesTable
              entries={clearedEntries}
              selectedIds={selectedCleared}
              onToggle={toggleCleared}
              showCheckbox={isDraft}
              formatMoney={formatMoney}
              formatDate={formatDate}
            />
          </section>
        )}
      </div>

      <ConfirmDialog
        isOpen={confirmAction === 'clear'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleClear}
        title="Clear entries"
        message={`Mark ${selectedUnclearedIds.length} selected item(s) as cleared?`}
        confirmText="Clear"
      />
      <ConfirmDialog
        isOpen={confirmAction === 'unclear'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleUnclear}
        title="Unclear entries"
        message={`Remove cleared status from ${selectedClearedIds.length} selected item(s)?`}
        confirmText="Unclear"
        variant="danger"
      />
      <ConfirmDialog
        isOpen={confirmAction === 'finalize'}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleFinalize}
        title="Finalize reconciliation"
        message="Once finalized, you cannot clear or unclear entries. Continue?"
        confirmText="Finalize"
        variant="danger"
      />

      <Modal
        isOpen={showAddLineModal}
        onClose={() => setShowAddLineModal(false)}
        title="Add statement line"
        size="md"
      >
        <div className="space-y-4">
          <FormField label="Date" required>
            <input
              type="date"
              value={addLineForm.line_date}
              onChange={(e) => setAddLineForm((f) => ({ ...f, line_date: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Amount (deposits positive, withdrawals negative)" required>
            <input
              type="number"
              step="any"
              value={addLineForm.amount}
              onChange={(e) => setAddLineForm((f) => ({ ...f, amount: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Description">
            <input
              type="text"
              value={addLineForm.description}
              onChange={(e) => setAddLineForm((f) => ({ ...f, description: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Reference">
            <input
              type="text"
              value={addLineForm.reference}
              onChange={(e) => setAddLineForm((f) => ({ ...f, reference: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setShowAddLineModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleAddStatementLine}
              disabled={
                addStatementLineMutation.isPending ||
                !addLineForm.line_date ||
                (addLineForm.amount !== '' && Number.isNaN(parseFloat(String(addLineForm.amount))))
              }
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {addStatementLineMutation.isPending ? 'Adding…' : 'Add'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={matchModalLine != null}
        onClose={() => setMatchModalLine(null)}
        title="Match to ledger entry"
        size="lg"
      >
        {matchModalLine && (
          <div>
            <p className="text-sm text-gray-600 mb-3">
              Statement line: {formatDate(matchModalLine.line_date)} — {formatMoney(matchModalLine.amount)}
            </p>
            {eligibleEntriesForMatch.length === 0 ? (
              <p className="text-sm text-gray-500">No eligible ledger entries (same sign and account).</p>
            ) : (
              <div className="max-h-64 overflow-y-auto border rounded-lg">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50 sticky top-0">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Date</th>
                      <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Description</th>
                      <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                      <th className="px-3 py-2 w-20"></th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {eligibleEntriesForMatch.map((e) => {
                      const amt = e.debit_amount > 0 ? e.debit_amount : -e.credit_amount;
                      return (
                        <tr key={e.ledger_entry_id} className="hover:bg-gray-50">
                          <td className="px-3 py-2 text-sm whitespace-nowrap">{formatDate(e.posting_date)}</td>
                          <td className="px-3 py-2 text-sm">{e.description ?? '—'}</td>
                          <td className="px-3 py-2 text-sm text-right tabular-nums">{formatMoney(amt)}</td>
                          <td className="px-3 py-2">
                            <button
                              type="button"
                              onClick={() => handleMatch(e.ledger_entry_id)}
                              className="text-[#1F6F5C] hover:text-[#1a5a4a] text-sm font-medium"
                            >
                              Match
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
            <div className="mt-3 flex justify-end">
              <button
                type="button"
                onClick={() => setMatchModalLine(null)}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Cancel
              </button>
            </div>
          </div>
        )}
      </Modal>

      <ConfirmDialog
        isOpen={voidLineId != null}
        onClose={() => setVoidLineId(null)}
        onConfirm={handleVoidLine}
        title="Void statement line"
        message="This will void the statement line. You can add a new line if needed."
        confirmText="Void"
        variant="danger"
      />
      <ConfirmDialog
        isOpen={unmatchLineId != null}
        onClose={() => setUnmatchLineId(null)}
        onConfirm={handleUnmatch}
        title="Unmatch"
        message="Remove the link between this statement line and the ledger entry?"
        confirmText="Unmatch"
        variant="danger"
      />
    </div>
  );
}

function EntriesTable({
  entries,
  amountKey,
  selectedIds,
  onToggle,
  showCheckbox,
  formatMoney,
  formatDate,
}: {
  entries: BankReconciliationLedgerEntryItem[];
  amountKey: 'debit_amount' | 'credit_amount';
  selectedIds: Set<string>;
  onToggle: (id: string) => void;
  showCheckbox: boolean;
  formatMoney: (n: number | string) => string;
  formatDate: (d: string) => string;
}) {
  if (entries.length === 0) {
    return (
      <div className="text-sm text-gray-500 py-4 bg-gray-50 rounded-lg px-4">
        No entries
      </div>
    );
  }
  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-[#E6ECEA]">
          <tr>
            {showCheckbox && (
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 w-10">
                <span className="sr-only">Select</span>
              </th>
            )}
            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
              Date
            </th>
            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
              Description
            </th>
            <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
              Amount
            </th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {entries.map((row) => (
            <tr key={row.ledger_entry_id} className="hover:bg-gray-50">
              {showCheckbox && (
                <td className="px-4 py-2">
                  <input
                    type="checkbox"
                    checked={selectedIds.has(row.ledger_entry_id)}
                    onChange={() => onToggle(row.ledger_entry_id)}
                    className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
                  />
                </td>
              )}
              <td className="px-4 py-2 text-sm text-gray-900 whitespace-nowrap">
                {formatDate(row.posting_date)}
              </td>
              <td className="px-4 py-2 text-sm text-gray-900">
                {row.description ?? '—'}
              </td>
              <td className="px-4 py-2 text-sm text-right tabular-nums">
                {formatMoney((row as unknown as Record<string, number>)[amountKey] ?? 0)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ClearedEntriesTable({
  entries,
  selectedIds,
  onToggle,
  showCheckbox,
  formatMoney,
  formatDate,
}: {
  entries: BankReconciliationLedgerEntryItem[];
  selectedIds: Set<string>;
  onToggle: (id: string) => void;
  showCheckbox: boolean;
  formatMoney: (n: number | string) => string;
  formatDate: (d: string) => string;
}) {
  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-[#E6ECEA]">
          <tr>
            {showCheckbox && (
              <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 w-10">
                <span className="sr-only">Select</span>
              </th>
            )}
            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
              Posting date
            </th>
            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
              Description
            </th>
            <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
              Debit
            </th>
            <th className="px-4 py-2 text-right text-xs font-medium text-gray-500">
              Credit
            </th>
            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500">
              Cleared date
            </th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {entries.map((row) => (
            <tr key={row.ledger_entry_id} className="hover:bg-gray-50">
              {showCheckbox && (
                <td className="px-4 py-2">
                  <input
                    type="checkbox"
                    checked={selectedIds.has(row.ledger_entry_id)}
                    onChange={() => onToggle(row.ledger_entry_id)}
                    className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
                  />
                </td>
              )}
              <td className="px-4 py-2 text-sm text-gray-900 whitespace-nowrap">
                {formatDate(row.posting_date)}
              </td>
              <td className="px-4 py-2 text-sm text-gray-900">
                {row.description ?? '—'}
              </td>
              <td className="px-4 py-2 text-sm text-right tabular-nums">
                {row.debit_amount > 0 ? formatMoney(row.debit_amount) : '—'}
              </td>
              <td className="px-4 py-2 text-sm text-right tabular-nums">
                {row.credit_amount > 0 ? formatMoney(row.credit_amount) : '—'}
              </td>
              <td className="px-4 py-2 text-sm text-gray-600 whitespace-nowrap">
                {row.cleared_date ? formatDate(row.cleared_date) : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
