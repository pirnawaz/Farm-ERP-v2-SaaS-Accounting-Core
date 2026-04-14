import { useMemo, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useWorkLogs, useWorkers } from '../../hooks/useLabour';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { Badge } from '../../components/Badge';
import { AdvancedWorkflowBanner } from '../../components/workflow/AdvancedWorkflowBanner';
import type { LabWorkLog } from '../../types';

export default function WorkLogsPage() {
  const [status, setStatus] = useState('');
  const [workerId, setWorkerId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data: workLogs, isLoading } = useWorkLogs({
    status: status || undefined,
    worker_id: workerId || undefined,
    crop_cycle_id: cropCycleId || undefined,
    project_id: projectId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: workers } = useWorkers({});
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();

  const logs = workLogs ?? [];
  const hasFilters = !!(status || workerId || cropCycleId || projectId || from || to);

  const clearFilters = () => {
    setStatus('');
    setWorkerId('');
    setCropCycleId('');
    setProjectId('');
    setFrom('');
    setTo('');
  };

  const filterSummaryBits = useMemo(() => {
    const bits: string[] = [];
    if (workerId) {
      const w = workers?.find((x) => x.id === workerId);
      if (w) bits.push(w.name);
    }
    if (status) {
      bits.push(
        status === 'DRAFT' ? 'Draft' : status === 'POSTED' ? 'Posted' : status === 'REVERSED' ? 'Reversed' : status,
      );
    }
    if (from && to) bits.push(`${from} → ${to}`);
    else if (from) bits.push(`from ${from}`);
    else if (to) bits.push(`to ${to}`);
    return bits;
  }, [workerId, workers, status, from, to]);

  const summaryLine = useMemo(() => {
    const n = logs.length;
    const label = n === 1 ? 'work log' : 'work logs';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (!hasFilters || filterSummaryBits.length === 0) return base;
    return `${base} · ${filterSummaryBits.join(' · ')}`;
  }, [logs.length, hasFilters, filterSummaryBits]);

  const cols: Column<LabWorkLog>[] = [
    {
      header: 'Date',
      accessor: (r) => (
        <span className="tabular-nums text-gray-900">{formatDate(r.work_date, { variant: 'medium' })}</span>
      ),
    },
    { header: 'Worker', accessor: (r) => <span className="font-medium text-gray-900">{r.worker?.name || r.worker_id}</span> },
    {
      header: 'Work recorded',
      accessor: (r) => (
        <span className="block max-w-[20rem] truncate text-gray-700" title={r.notes || r.doc_no || undefined}>
          {r.notes || r.doc_no || '—'}
        </span>
      ),
    },
    { header: 'Units', accessor: (r) => <span className="tabular-nums text-right block">{r.units ?? '—'}</span> },
    { header: 'Reference', accessor: (r) => <span className="tabular-nums">{r.doc_no}</span> },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status === 'DRAFT' ? 'Draft' : r.status === 'POSTED' ? 'Posted' : 'Reversed'}
        </Badge>
      ),
    },
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <AdvancedWorkflowBanner />
      <PageHeader
        title="Labour Work Logs"
        tooltip="Track labour activity recorded for workers."
        description="Advanced/manual labour workflow. Kept for history, audit, and non-field exceptions."
        helper="Use Field Jobs for normal crop-field work. Only use Labour Work Logs for legacy records or exceptional/manual entries that are not part of a Field Job."
        backTo="/app/labour"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Labour Overview', to: '/app/labour' },
          { label: 'Labour Work Logs' },
        ]}
        right={
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => navigate('/app/crop-ops/field-jobs/new')}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
            >
              New field job
            </button>
          </div>
        }
      />
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        <p className="font-medium">Advisory: avoid duplicate crop-work records</p>
        <p className="mt-1 text-amber-900/90">
          <span className="font-medium">Use Field Jobs</span> for normal crop-field work. Only create manual Labour Work Logs
          for legacy records or exceptional/manual cases. Recording the same event in both workflows can create duplicate
          operational and accounting records.
        </p>
      </div>

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
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <div>
            <label htmlFor="lw-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="lw-status"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
              <option value="REVERSED">Reversed</option>
            </select>
          </div>
          <div>
            <label htmlFor="lw-worker" className="block text-xs font-medium text-gray-600 mb-1">
              Worker
            </label>
            <select
              id="lw-worker"
              value={workerId}
              onChange={(e) => setWorkerId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {workers?.map((w) => (
                <option key={w.id} value={w.id}>
                  {w.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="lw-cycle" className="block text-xs font-medium text-gray-600 mb-1">
              Crop cycle
            </label>
            <select
              id="lw-cycle"
              value={cropCycleId}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setProjectId('');
              }}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {cropCycles?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="lw-project" className="block text-xs font-medium text-gray-600 mb-1">
              Field cycle
            </label>
            <select
              id="lw-project"
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="lw-from" className="block text-xs font-medium text-gray-600 mb-1">
              From
            </label>
            <input
              id="lw-from"
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div>
            <label htmlFor="lw-to" className="block text-xs font-medium text-gray-600 mb-1">
              To
            </label>
            <input
              id="lw-to"
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
      ) : logs.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No work logs yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">Existing records remain available for history and testing.</p>
        </div>
      ) : logs.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No work logs match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting filters or clear them to see all logs.</p>
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
            data={logs as LabWorkLog[]}
            columns={cols}
            onRowClick={(r) => navigate(`/app/labour/work-logs/${r.id}`, { state: { from: location.pathname + location.search } })}
            emptyMessage=""
          />
        </div>
      )}
    </div>
  );
}
