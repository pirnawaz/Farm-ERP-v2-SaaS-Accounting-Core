import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useJournalEntry, useReverseJournal } from '../../hooks/useJournalEntries';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useFormatting } from '../../hooks/useFormatting';
import type { JournalEntry } from '../../types';

export default function JournalDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { formatMoney, formatDate } = useFormatting();
  const { data: journal, isLoading, error } = useJournalEntry(id ?? undefined);
  const reverseM = useReverseJournal(id ?? '');

  const [showReverseConfirm, setShowReverseConfirm] = useState(false);

  const isDraft = journal?.status === 'DRAFT';
  const isPosted = journal?.status === 'POSTED';
  const isReversed = journal?.status === 'REVERSED';

  const handleReverse = async () => {
    await reverseM.mutateAsync({});
    setShowReverseConfirm(false);
  };

  if (isLoading || !journal) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-4 text-red-800 bg-red-50 rounded-md">
        Failed to load journal. <button type="button" onClick={() => navigate('/app/accounting/journals')} className="underline">Back to list</button>
      </div>
    );
  }

  const j = journal as JournalEntry & { total_debits?: number; total_credits?: number };
  const totalDebits = j.total_debits ?? j.lines?.reduce((s, l) => s + Number(l.debit_amount), 0) ?? 0;
  const totalCredits = j.total_credits ?? j.lines?.reduce((s, l) => s + Number(l.credit_amount), 0) ?? 0;

  return (
    <div>
      <PageHeader
        title={`Journal ${j.journal_number}`}
        backTo="/app/accounting/journals"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'General Journal', to: '/app/accounting/journals' },
          { label: j.journal_number },
        ]}
        right={
          isDraft ? (
            <button
              type="button"
              onClick={() => navigate(`/app/accounting/journals/${id}/edit`)}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
            >
              Edit
            </button>
          ) : isPosted ? (
            <button
              type="button"
              onClick={() => setShowReverseConfirm(true)}
              disabled={reverseM.isPending}
              className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50"
            >
              Reverse
            </button>
          ) : null
        }
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm text-gray-500">Number</dt>
            <dd className="font-medium">{j.journal_number}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Entry date</dt>
            <dd>{formatDate(j.entry_date)}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Status</dt>
            <dd>
              <span
                className={`px-2 py-1 rounded text-sm ${
                  isPosted ? 'bg-green-100 text-green-800' : isReversed ? 'bg-gray-100 text-gray-800' : 'bg-amber-100 text-amber-800'
                }`}
              >
                {j.status}
              </span>
            </dd>
          </div>
          {j.posted_at && (
            <div>
              <dt className="text-sm text-gray-500">Posted at</dt>
              <dd>{formatDate(j.posted_at)}</dd>
            </div>
          )}
          {j.reversed_at && (
            <div>
              <dt className="text-sm text-gray-500">Reversed at</dt>
              <dd>{formatDate(j.reversed_at)}</dd>
            </div>
          )}
          {j.memo && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Memo</dt>
              <dd>{j.memo}</dd>
            </div>
          )}
        </dl>

        <div>
          <h3 className="font-medium text-gray-900 mb-2">Lines</h3>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead>
                <tr className="bg-gray-50">
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Account</th>
                  <th className="px-3 py-2 text-left text-xs font-medium text-gray-600">Description</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Debit</th>
                  <th className="px-3 py-2 text-right text-xs font-medium text-gray-600">Credit</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {(j.lines ?? []).map((line) => (
                  <tr key={line.id}>
                    <td className="px-3 py-2">
                      {line.account?.code ?? line.account_id} — {line.account?.name ?? '—'}
                    </td>
                    <td className="px-3 py-2 text-gray-600">{line.description ?? '—'}</td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {Number(line.debit_amount) > 0 ? formatMoney(Number(line.debit_amount)) : '—'}
                    </td>
                    <td className="px-3 py-2 text-right tabular-nums">
                      {Number(line.credit_amount) > 0 ? formatMoney(Number(line.credit_amount)) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        <div className="flex justify-end gap-6 pt-4 border-t tabular-nums text-sm">
          <span>Total debits: {formatMoney(totalDebits)}</span>
          <span>Total credits: {formatMoney(totalCredits)}</span>
        </div>

        {j.posting_group_id && (
          <p className="text-sm text-gray-500">
            Posting group: {j.posting_group_id}
            {j.posting_group?.posting_date && ` (${formatDate(j.posting_group.posting_date)})`}
          </p>
        )}
        {j.reversal_posting_group_id && (
          <p className="text-sm text-gray-500">
            Reversal posting group: {j.reversal_posting_group_id}
          </p>
        )}
      </div>

      <ConfirmDialog
        isOpen={showReverseConfirm}
        onClose={() => setShowReverseConfirm(false)}
        onConfirm={handleReverse}
        title="Reverse journal"
        message="This will create a reversal posting and mark this journal as reversed. Continue?"
        confirmText="Reverse"
        variant="danger"
      />
    </div>
  );
}
