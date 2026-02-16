import { useState } from 'react';
import { useAccountingPeriods, useCreateAccountingPeriod, useCloseAccountingPeriod, useReopenAccountingPeriod } from '../../hooks/useAccountingPeriods';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useFormatting } from '../../hooks/useFormatting';
import type { AccountingPeriod } from '../../types';

export default function AccountingPeriodsPage() {
  const { formatDate } = useFormatting();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [createForm, setCreateForm] = useState({ period_start: '', period_end: '', name: '' });
  const [closeTarget, setCloseTarget] = useState<AccountingPeriod | null>(null);
  const [closeNotes, setCloseNotes] = useState('');
  const [reopenTarget, setReopenTarget] = useState<AccountingPeriod | null>(null);
  const [reopenNotes, setReopenNotes] = useState('');

  const { data: list = [], isLoading } = useAccountingPeriods({});
  const createM = useCreateAccountingPeriod();
  const closeM = useCloseAccountingPeriod(closeTarget?.id ?? '');
  const reopenM = useReopenAccountingPeriod(reopenTarget?.id ?? '');

  const handleCreate = async () => {
    if (!createForm.period_start || !createForm.period_end) return;
    try {
      await createM.mutateAsync({
        period_start: createForm.period_start,
        period_end: createForm.period_end,
        name: createForm.name.trim() || undefined,
      });
      setShowCreateModal(false);
      setCreateForm({ period_start: '', period_end: '', name: '' });
    } catch {
      // toast in hook
    }
  };

  const handleClose = async () => {
    if (!closeTarget) return;
    await closeM.mutateAsync({ notes: closeNotes.trim() || undefined });
    setCloseTarget(null);
    setCloseNotes('');
  };

  const handleReopen = async () => {
    if (!reopenTarget) return;
    await reopenM.mutateAsync({ notes: reopenNotes.trim() || undefined });
    setReopenTarget(null);
    setReopenNotes('');
  };

  const rows = list.map((p) => ({ ...p, id: p.id }));

  const columns: Column<AccountingPeriod & { id: string }>[] = [
    { header: 'Name', accessor: (row) => row.name },
    { header: 'Start', accessor: (row) => formatDate(row.period_start) },
    { header: 'End', accessor: (row) => formatDate(row.period_end) },
    {
      header: 'Status',
      accessor: (row) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            row.status === 'OPEN' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
          }`}
        >
          {row.status}
        </span>
      ),
    },
    { header: 'Closed at', accessor: (row) => (row.closed_at ? formatDate(row.closed_at) : '—') },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex gap-2">
          {row.status === 'OPEN' && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setCloseTarget(row);
              }}
              className="text-sm text-amber-600 hover:underline"
            >
              Close
            </button>
          )}
          {row.status === 'CLOSED' && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                setReopenTarget(row);
              }}
              className="text-sm text-blue-600 hover:underline"
            >
              Reopen
            </button>
          )}
        </div>
      ),
    },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Accounting periods"
        backTo="/app/accounting/journals"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'General Journal', to: '/app/accounting/journals' },
          { label: 'Periods' },
        ]}
        right={
          <button
            type="button"
            onClick={() => setShowCreateModal(true)}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New period
          </button>
        }
      />

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={rows}
          columns={columns}
          emptyMessage="No accounting periods. Create one to control posting by date."
        />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="New accounting period"
        size="md"
      >
        <div className="space-y-4">
          <FormField label="Period start" required>
            <input
              type="date"
              value={createForm.period_start}
              onChange={(e) => setCreateForm({ ...createForm, period_start: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Period end" required>
            <input
              type="date"
              value={createForm.period_end}
              onChange={(e) => setCreateForm({ ...createForm, period_end: e.target.value })}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Name (optional)">
            <input
              type="text"
              value={createForm.name}
              onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })}
              placeholder="e.g. 2026-02"
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setShowCreateModal(false)}
              className="px-4 py-2 text-gray-700 border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleCreate}
              disabled={!createForm.period_start || !createForm.period_end || createM.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {createM.isPending ? 'Creating…' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!closeTarget}
        onClose={() => { setCloseTarget(null); setCloseNotes(''); }}
        title="Close period"
        size="sm"
      >
        {closeTarget && (
          <div>
            <p className="text-sm text-gray-600 mb-4">
              Close period &quot;{closeTarget.name}&quot;? Posting will be blocked for dates in this period.
            </p>
            <FormField label="Notes (optional)">
              <input
                type="text"
                value={closeNotes}
                onChange={(e) => setCloseNotes(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                placeholder="e.g. Month-end close"
              />
            </FormField>
            <div className="flex justify-end gap-3 mt-4">
              <button type="button" onClick={() => { setCloseTarget(null); setCloseNotes(''); }} className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
              <button type="button" onClick={handleClose} disabled={closeM.isPending} className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50">Close period</button>
            </div>
          </div>
        )}
      </Modal>

      <Modal
        isOpen={!!reopenTarget}
        onClose={() => { setReopenTarget(null); setReopenNotes(''); }}
        title="Reopen period"
        size="sm"
      >
        {reopenTarget && (
          <div>
            <p className="text-sm text-gray-600 mb-4">
              Reopen period &quot;{reopenTarget.name}&quot;? Posting will be allowed again for dates in this period.
            </p>
            <FormField label="Notes (optional)">
              <input
                type="text"
                value={reopenNotes}
                onChange={(e) => setReopenNotes(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md"
                placeholder="e.g. Correction required"
              />
            </FormField>
            <div className="flex justify-end gap-3 mt-4">
              <button type="button" onClick={() => { setReopenTarget(null); setReopenNotes(''); }} className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>
              <button type="button" onClick={handleReopen} disabled={reopenM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50">Reopen</button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
