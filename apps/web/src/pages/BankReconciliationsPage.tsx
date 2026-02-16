import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useBankReconciliations, useCreateBankReconciliation } from '../hooks/useBankReconciliations';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { useFormatting } from '../hooks/useFormatting';
import type { BankReconciliationListItem, BankReconciliationAccountCode, CreateBankReconciliationPayload } from '../types';

type TabCode = BankReconciliationAccountCode | '';

export default function BankReconciliationsPage() {
  const navigate = useNavigate();
  const { formatMoney, formatDate } = useFormatting();
  const [tab, setTab] = useState<TabCode>('BANK');
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [formData, setFormData] = useState<CreateBankReconciliationPayload>({
    account_code: 'BANK',
    statement_date: new Date().toISOString().split('T')[0],
    statement_balance: '',
    notes: '',
  });

  const accountCode = tab === 'BANK' || tab === 'CASH' ? tab : undefined;
  const { data: list = [], isLoading } = useBankReconciliations(accountCode);
  const createMutation = useCreateBankReconciliation();

  const openCreateModal = () => {
    setFormData({
      account_code: tab === 'CASH' ? 'CASH' : 'BANK',
      statement_date: new Date().toISOString().split('T')[0],
      statement_balance: '',
      notes: '',
    });
    setShowCreateModal(true);
  };

  const handleCreate = async () => {
    const balance = formData.statement_balance;
    const numBalance = typeof balance === 'string' ? parseFloat(balance) : balance;
    if (Number.isNaN(numBalance)) {
      return;
    }
    try {
      const created = await createMutation.mutateAsync({
        account_code: formData.account_code,
        statement_date: formData.statement_date,
        statement_balance: numBalance,
        notes: formData.notes?.trim() || undefined,
      });
      setShowCreateModal(false);
      navigate(`/app/reports/bank-reconciliation/${created.id}`);
    } catch {
      // toast handled in hook
    }
  };

  const rows = list.map((item: BankReconciliationListItem & { account?: { code: string }; finalized_at?: string | null }) => ({
    ...item,
    id: item.id,
    account_code: item.account_code ?? item.account?.code ?? '',
  }));

  const columns: Column<typeof rows[0]>[] = [
    {
      header: 'Statement date',
      accessor: (row) => formatDate(row.statement_date),
    },
    {
      header: 'Statement balance',
      accessor: (row) => (
        <span className="tabular-nums">{formatMoney(Number(row.statement_balance))}</span>
      ),
    },
    {
      header: 'Status',
      accessor: (row) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            row.status === 'FINALIZED'
              ? 'bg-green-100 text-green-800'
              : row.status === 'DRAFT'
                ? 'bg-amber-100 text-amber-800'
                : 'bg-gray-100 text-gray-800'
          }`}
        >
          {row.status}
        </span>
      ),
    },
    {
      header: 'Created',
      accessor: (row) => formatDate(row.created_at),
    },
    {
      header: 'Finalized',
      accessor: (row) =>
        row.finalized_at ? formatDate(row.finalized_at) : '—',
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
        title="Bank Reconciliation"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Bank Reconciliation' }]}
        right={
          <button
            type="button"
            onClick={openCreateModal}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New reconciliation
          </button>
        }
      />

      <div className="flex gap-2 mb-4">
        {(['BANK', 'CASH'] as const).map((code) => (
          <button
            key={code}
            type="button"
            onClick={() => setTab(code)}
            className={`px-4 py-2 rounded-md text-sm font-medium ${
              tab === code
                ? 'bg-[#1F6F5C] text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            {code}
          </button>
        ))}
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={rows}
          columns={columns}
          onRowClick={(row) => navigate(`/app/reports/bank-reconciliation/${row.id}`)}
          emptyMessage="No reconciliations yet. Create one to get started."
        />
      </div>

      <Modal
        isOpen={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        title="New reconciliation"
        size="md"
      >
        <div className="space-y-4">
          <FormField label="Account" required>
            <select
              value={formData.account_code}
              onChange={(e) =>
                setFormData({
                  ...formData,
                  account_code: e.target.value as BankReconciliationAccountCode,
                })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="BANK">BANK</option>
              <option value="CASH">CASH</option>
            </select>
          </FormField>
          <FormField label="Statement date" required>
            <input
              type="date"
              value={formData.statement_date}
              onChange={(e) =>
                setFormData({ ...formData, statement_date: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Statement balance" required>
            <input
              type="number"
              step="any"
              value={formData.statement_balance}
              onChange={(e) =>
                setFormData({ ...formData, statement_balance: e.target.value })
              }
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Notes">
            <textarea
              value={formData.notes ?? ''}
              onChange={(e) =>
                setFormData({ ...formData, notes: e.target.value })
              }
              rows={2}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <div className="flex justify-end gap-3">
            <button
              type="button"
              onClick={() => setShowCreateModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleCreate}
              disabled={
                createMutation.isPending ||
                !formData.statement_date ||
                (formData.statement_balance !== '' &&
                  Number.isNaN(parseFloat(String(formData.statement_balance))))
              }
              className="px-4 py-2 text-sm font-medium text-white bg-[#1F6F5C] rounded-md hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createMutation.isPending ? 'Creating…' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
