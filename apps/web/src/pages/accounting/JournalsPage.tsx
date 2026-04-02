import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useJournalEntries } from '../../hooks/useJournalEntries';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { FilterBar, FilterField, FilterGrid } from '../../components/FilterBar';
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

  const rows = list.map((item) => ({ ...item, id: item.id }));

  return (
    <div className="space-y-6">
      <PageHeader
        title="General Journal"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'General Journal' },
        ]}
        right={
          <button
            type="button"
            onClick={() => navigate('/app/accounting/journals/new')}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New journal
          </button>
        }
      />

      <div className="space-y-4">
        <FilterBar>
          <FilterGrid className="lg:grid-cols-4 xl:grid-cols-4">
            <FilterField label="From">
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </FilterField>
            <FilterField label="To">
              <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </FilterField>
            <FilterField label="Status">
              <select value={status} onChange={(e) => setStatus(e.target.value as JournalEntryStatus | '')}>
                <option value="">All</option>
                <option value="DRAFT">Draft</option>
                <option value="POSTED">Posted</option>
                <option value="REVERSED">Reversed</option>
              </select>
            </FilterField>
            <FilterField label="Search">
              <input
                type="text"
                placeholder="Number or memo"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </FilterField>
          </FilterGrid>
        </FilterBar>

        <div className="bg-white rounded-lg shadow">
          {isLoading ? (
            <div className="flex justify-center py-12">
              <LoadingSpinner size="lg" />
            </div>
          ) : (
            <DataTable
              data={rows}
              columns={columns}
              onRowClick={(row) => navigate(`/app/accounting/journals/${row.id}`)}
              emptyMessage="No journal entries found."
            />
          )}
        </div>
      </div>
    </div>
  );
}
