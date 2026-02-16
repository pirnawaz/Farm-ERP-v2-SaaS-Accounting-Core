import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useCreateJournal, useUpdateJournal, usePostJournal, useJournalEntry } from '../../hooks/useJournalEntries';
import { journalsApi } from '../../api/journals';
import { accountsApi } from '../../api/accounts';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import toast from 'react-hot-toast';
import type { JournalEntryLinePayload } from '../../types';

type LineRow = {
  account_id: string;
  description: string;
  debit_amount: string;
  credit_amount: string;
};

const emptyLine = (): LineRow => ({
  account_id: '',
  description: '',
  debit_amount: '0',
  credit_amount: '0',
});

export default function JournalFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { formatMoney } = useFormatting();
  const isNew = id === 'new';

  const { data: accounts = [], isLoading: accountsLoading } = useQuery({
    queryKey: ['accounts'],
    queryFn: () => accountsApi.list(),
  });

  const { data: existingJournal, isLoading: journalLoading } = useJournalEntry(isNew ? undefined : id ?? undefined);

  const [entryDate, setEntryDate] = useState(new Date().toISOString().split('T')[0]);
  const [memo, setMemo] = useState('');
  const [lines, setLines] = useState<LineRow[]>([emptyLine(), emptyLine()]);
  const [initialized, setInitialized] = useState(false);

  const createM = useCreateJournal();
  const updateM = useUpdateJournal(id && !isNew ? id : '');
  const postM = usePostJournal(id && !isNew ? id : '');

  // Prefill from existing journal when editing
  useEffect(() => {
    if (isNew || !existingJournal || initialized) return;
    if (existingJournal.status !== 'DRAFT') {
      navigate(`/app/accounting/journals/${id}`, { replace: true });
      return;
    }
    setEntryDate(existingJournal.entry_date?.slice(0, 10) ?? entryDate);
    setMemo(existingJournal.memo ?? '');
    if (existingJournal.lines?.length) {
      setLines(
        existingJournal.lines.map((l) => ({
          account_id: l.account_id,
          description: (l.description ?? '').toString(),
          debit_amount: String(l.debit_amount ?? 0),
          credit_amount: String(l.credit_amount ?? 0),
        }))
      );
    }
    setInitialized(true);
  }, [existingJournal, id, isNew, initialized, navigate]);

  const addLine = () => setLines((l) => [...l, emptyLine()]);
  const removeLine = (i: number) => setLines((l) => (l.length > 2 ? l.filter((_, idx) => idx !== i) : l));
  const updateLine = (i: number, f: Partial<LineRow>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const numericLines = lines.map((l) => ({
    debit: parseFloat(l.debit_amount) || 0,
    credit: parseFloat(l.credit_amount) || 0,
  }));
  const totalDebits = numericLines.reduce((a, b) => a + b.debit, 0);
  const totalCredits = numericLines.reduce((a, b) => a + b.credit, 0);
  const difference = Math.round((totalDebits - totalCredits) * 100) / 100;
  const isBalanced = difference === 0;

  const buildPayload = (): { entry_date: string; memo?: string; lines: JournalEntryLinePayload[] } => {
    const validLines = lines
      .filter((l) => l.account_id && ((parseFloat(l.debit_amount) || 0) > 0 !== (parseFloat(l.credit_amount) || 0) > 0))
      .map((l) => ({
        account_id: l.account_id,
        description: l.description.trim() || undefined,
        debit_amount: parseFloat(l.debit_amount) || 0,
        credit_amount: parseFloat(l.credit_amount) || 0,
      }));
    return {
      entry_date: entryDate,
      memo: memo.trim() || undefined,
      lines: validLines,
    };
  };

  const handleSaveDraft = async () => {
    const payload = buildPayload();
    if (payload.lines.length < 2) {
      return;
    }
    if (isNew) {
      const created = await createM.mutateAsync(payload);
      navigate(`/app/accounting/journals/${created.id}`);
    } else {
      await updateM.mutateAsync({ ...payload, lines: payload.lines });
    }
  };

  const handlePost = async () => {
    if (!isBalanced || buildPayload().lines.length < 2) return;
    try {
      if (isNew) {
        const created = await createM.mutateAsync(buildPayload());
        await journalsApi.post(created.id);
        toast.success('Journal posted');
        navigate(`/app/accounting/journals/${created.id}`);
      } else {
        await postM.mutateAsync();
        navigate(`/app/accounting/journals/${id}`);
      }
    } catch {
      // toasts handled by mutations or API error
    }
  };

  if (accountsLoading || (!isNew && journalLoading && !existingJournal)) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!isNew && existingJournal && existingJournal.status !== 'DRAFT') {
    return null;
  }

  return (
    <div>
      <PageHeader
        title={isNew ? 'New journal' : 'Edit journal'}
        backTo="/app/accounting/journals"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'General Journal', to: '/app/accounting/journals' },
          { label: isNew ? 'New' : 'Edit' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Entry date" required>
            <input
              type="date"
              value={entryDate}
              onChange={(e) => setEntryDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Memo">
            <input
              type="text"
              value={memo}
              onChange={(e) => setMemo(e.target.value)}
              placeholder="Optional memo"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium text-gray-900">Lines</h3>
            <button
              type="button"
              onClick={addLine}
              className="text-sm text-[#1F6F5C] hover:underline"
            >
              + Add line
            </button>
          </div>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead>
                <tr className="bg-gray-50">
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Account</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Description</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Debit</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Credit</th>
                  <th className="w-10" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">
                      <select
                        value={line.account_id}
                        onChange={(e) => updateLine(i, { account_id: e.target.value })}
                        className="w-full max-w-[200px] px-2 py-1.5 border border-gray-300 rounded text-sm"
                      >
                        <option value="">Select account</option>
                        {accounts.map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.code} — {a.name}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        value={line.description}
                        onChange={(e) => updateLine(i, { description: e.target.value })}
                        placeholder="Optional"
                        className="w-full max-w-[180px] px-2 py-1.5 border border-gray-300 rounded text-sm"
                      />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.debit_amount}
                        onChange={(e) => updateLine(i, { debit_amount: e.target.value, credit_amount: '0' })}
                        className="w-24 px-2 py-1.5 border border-gray-300 rounded text-sm text-right tabular-nums"
                      />
                    </td>
                    <td className="px-3 py-2 text-right">
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={line.credit_amount}
                        onChange={(e) => updateLine(i, { credit_amount: e.target.value, debit_amount: '0' })}
                        className="w-24 px-2 py-1.5 border border-gray-300 rounded text-sm text-right tabular-nums"
                      />
                    </td>
                    <td>
                      <button
                        type="button"
                        onClick={() => removeLine(i)}
                        className="text-red-600 hover:underline text-sm"
                      >
                        Remove
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex justify-end gap-4 pt-4 border-t">
          <div className="flex items-center gap-6 tabular-nums text-sm">
            <span>Total debits: {formatMoney(totalDebits)}</span>
            <span>Total credits: {formatMoney(totalCredits)}</span>
            <span className={difference !== 0 ? 'text-amber-600 font-medium' : ''}>
              Difference: {formatMoney(difference)}
            </span>
          </div>
        </div>

        <div className="flex justify-end gap-3 pt-4">
          <button
            type="button"
            onClick={() => navigate('/app/accounting/journals')}
            className="px-4 py-2 text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSaveDraft}
            disabled={createM.isPending || updateM.isPending || buildPayload().lines.length < 2}
            className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 disabled:opacity-50"
          >
            {isNew ? 'Save draft' : 'Update draft'}
          </button>
          <button
            type="button"
            onClick={handlePost}
            disabled={
              !isBalanced ||
              buildPayload().lines.length < 2 ||
              createM.isPending ||
              postM.isPending
            }
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {isNew ? 'Save and post' : 'Post'}
          </button>
        </div>
      </div>
    </div>
  );
}
