import { useMemo, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useHarvests } from '../../hooks/useHarvests';
import { useCropCycles } from '../../hooks/useCropCycles';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { Badge } from '../../components/Badge';
import { useFormatting } from '../../hooks/useFormatting';
import type { Harvest } from '../../types';
import { PrimaryWorkflowBanner } from '../../components/workflow/PrimaryWorkflowBanner';

function statusLabel(s: string): string {
  if (s === 'DRAFT') return 'Draft';
  if (s === 'POSTED') return 'Posted';
  if (s === 'REVERSED') return 'Reversed';
  return s;
}

export default function HarvestsPage() {
  const [status, setStatus] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data: harvests, isLoading } = useHarvests({
    status: status || undefined,
    crop_cycle_id: cropCycleId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: cropCycles } = useCropCycles();
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();

  const rows = (harvests ?? []) as Harvest[];
  const hasFilters = !!(status || cropCycleId || from || to);

  const summaryLine = useMemo(() => {
    const n = rows.length;
    const label = n === 1 ? 'harvest' : 'harvests';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    return base;
  }, [rows.length, hasFilters]);

  const clearFilters = () => {
    setStatus('');
    setCropCycleId('');
    setFrom('');
    setTo('');
  };

  const totalQty = (h: Harvest) => {
    return (h.lines || []).reduce((s, l) => s + parseFloat(String(l.quantity || 0)), 0);
  };

  const cols: Column<Harvest>[] = [
    {
      header: 'Date',
      accessor: (r) => (
        <span className="tabular-nums text-gray-900">{formatDate(r.harvest_date, { variant: 'medium' })}</span>
      ),
    },
    { header: 'Harvest no.', accessor: (r) => <span className="tabular-nums text-gray-800">{r.harvest_no || '—'}</span> },
    { header: 'Crop cycle', accessor: (r) => <span className="text-gray-900">{r.crop_cycle?.name || r.crop_cycle_id}</span> },
    { header: 'Field cycle', accessor: (r) => <span className="text-gray-800">{r.project?.name ?? '—'}</span> },
    {
      header: 'Total qty',
      accessor: (r) => <span className="tabular-nums text-right block text-gray-900">{totalQty(r).toFixed(3)}</span>,
      numeric: true,
    },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {statusLabel(r.status)}
        </Badge>
      ),
    },
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Harvests"
        description="Record harvest output and how it is shared—quantities, stores, and share lines in one flow."
        helper="Harvest share lines replace separate manual settlement for shared crop output; add them on each harvest instead of duplicating elsewhere."
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Harvests' },
        ]}
        right={
          <button
            type="button"
            onClick={() => navigate('/app/harvests/new')}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
          >
            New harvest
          </button>
        }
      />

      <PrimaryWorkflowBanner variant="harvest" />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label htmlFor="hv-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="hv-status"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All statuses</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
              <option value="REVERSED">Reversed</option>
            </select>
          </div>
          <div>
            <label htmlFor="hv-cycle" className="block text-xs font-medium text-gray-600 mb-1">
              Crop cycle
            </label>
            <select
              id="hv-cycle"
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All crop cycles</option>
              {cropCycles?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="hv-from" className="block text-xs font-medium text-gray-600 mb-1">
              From
            </label>
            <input
              id="hv-from"
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div>
            <label htmlFor="hv-to" className="block text-xs font-medium text-gray-600 mb-1">
              To
            </label>
            <input
              id="hv-to"
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : rows.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No harvests yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Harvest records appear here once harvest activity is recorded against your crop operations.
          </p>
          <button
            type="button"
            onClick={() => navigate('/app/harvests/new')}
            className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
          >
            New harvest
          </button>
        </div>
      ) : rows.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No harvests match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try widening dates or clearing filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable
            data={rows}
            columns={cols}
            onRowClick={(r) => navigate(`/app/harvests/${r.id}`, { state: { from: location.pathname + location.search } })}
            emptyMessage=""
          />
        </div>
      )}
    </div>
  );
}
