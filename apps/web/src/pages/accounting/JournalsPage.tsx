import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useJournalEntries } from '../../hooks/useJournalEntries';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { JournalEntry, JournalEntryStatus } from '../../types';

const defaultFrom = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0];
const defaultTo = new Date().toISOString().split('T')[0];

export default function JournalsPage() {
  const navigate = useNavigate();
  const { formatDate } = useFormatting();
  const [from, setFrom] = useState(defaultFrom);
  const [to, setTo] = useState(defaultTo);
  const [status, setStatus] = useState<JournalEntryStatus | ''>('');
  const [q, setQ] = useState('');

  const { data: list = [], isLoading } = useJournalEntries({
    from,
    to,
    status: status || undefined,
    q: q || undefined,
    limit: 50,
  });

  const columns: Column<JournalEntry & { id: string }>[] = [
    { header: 'Number', accessor: (row) => row.journal_number },
    { header: 'Date', accessor: (row) => formatDate(row.entry_date) },
    { header: 'Memo', accessor: (row) => (row.memo ?? '—').slice(0, 40) },
    {
      header: 'Status',
      accessor: (row) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            row.status === 'POSTED'
              ? 'bg-green-100 text-green-800'
              : row.status === 'REVERSED'
                ? 'bg-gray-100 text-gray-800'
                : 'bg-amber-100 text-amber-800'
          }`}
        >
          {row.status}
        </span>
      ),
    },
    { header: 'Created', accessor: (row) => formatDate(row.created_at) },
  ];

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  const rows = list.map((item) => ({ ...item, id: item.id }));

  return (
    <div>
      <PageHeader
        title="General Journal"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Reports', to: '/app/reports' },
          { label: 'General Journal' },
        ]}
        right={
          <button
            type="button"
            onClick={() => navigate('/app/accounting/journals/new')}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New journal
          </button>
        }
      />

      <div className="flex flex-wrap gap-4 mb-4 items-end">
        <label className="flex flex-col gap-1">
          <span className="text-sm text-gray-600">From</span>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-sm text-gray-600">To</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-sm text-gray-600">Status</span>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as JournalEntryStatus | '')}
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            <option value="">All</option>
            <option value="DRAFT">Draft</option>
            <option value="POSTED">Posted</option>
            <option value="REVERSED">Reversed</option>
          </select>
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-sm text-gray-600">Search</span>
          <input
            type="text"
            placeholder="Number or memo"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            className="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] min-w-[180px]"
          />
        </label>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={rows}
          columns={columns}
          onRowClick={(row) => navigate(`/app/accounting/journals/${row.id}`)}
          emptyMessage="No journal entries found."
        />
      </div>
    </div>
  );
}
