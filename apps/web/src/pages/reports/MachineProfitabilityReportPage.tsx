import { useMemo, useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import { useMachineProfitabilityReport } from '../../hooks/useReports';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useProjects, useProject } from '../../hooks/useProjects';
import { DataTable, type Column } from '../../components/DataTable';
import { ReportEmptyStateCard, ReportPage, ReportSectionCard } from '../../components/report';
import { EMPTY_COPY } from '../../config/presentation';
import type { MachineProfitabilityApiRow } from '../../types';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

type Row = MachineProfitabilityApiRow & { id: string; label: string };

export default function MachineProfitabilityReportPage() {
  const { formatMoney } = useFormatting();
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [projectId, setProjectId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');

  const { data: projects } = useProjects();
  const { data: projectMeta } = useProject(projectId || '');
  const projectCropId = projectMeta?.crop_cycle_id ?? '';

  const params = useMemo(
    () => ({
      from,
      to,
      ...(projectId ? { project_id: projectId } : {}),
      ...(cropCycleId ? { crop_cycle_id: cropCycleId } : {}),
    }),
    [from, to, projectId, cropCycleId]
  );

  const { data: rows, isLoading, error } = useMachineProfitabilityReport(params);
  const { data: machines } = useMachinesQuery();

  const machineLabel = useMemo(() => {
    const m = new Map<string, { code: string; name: string }>();
    (machines ?? []).forEach((x) => m.set(x.id, { code: x.code, name: x.name }));
    return (id: string) => {
      const found = m.get(id);
      if (found) return `${found.code} — ${found.name}`;
      return id.slice(0, 8) + '…';
    };
  }, [machines]);

  const tableRows: Row[] = useMemo(() => {
    if (!rows?.length) return [];
    return rows.map((r) => ({
      ...r,
      id: r.machine_id,
      label: machineLabel(r.machine_id),
    }));
  }, [rows, machineLabel]);

  const totals = useMemo(() => {
    if (!rows?.length) return null;
    return rows.reduce(
      (acc, r) => ({
        revenue: acc.revenue + r.revenue,
        cost: acc.cost + r.cost,
        profit: acc.profit + r.profit,
        hours: acc.hours + r.usage_hours,
      }),
      { revenue: 0, cost: 0, profit: 0, hours: 0 }
    );
  }, [rows]);

  const columns: Column<Row>[] = [
    {
      header: 'Machine',
      accessor: (r) => <span className="font-medium text-gray-900">{r.label}</span>,
    },
    {
      header: 'Hours',
      accessor: (r) => <span className="tabular-nums text-right block">{r.usage_hours.toFixed(2)}</span>,
    },
    {
      header: 'Money in',
      accessor: (r) => <span className="tabular-nums text-right block text-emerald-800">{formatMoney(r.revenue)}</span>,
    },
    {
      header: 'Money out',
      accessor: (r) => <span className="tabular-nums text-right block text-rose-800">{formatMoney(r.cost)}</span>,
    },
    {
      header: 'What’s left',
      accessor: (r) => (
        <span className={`tabular-nums text-right block font-semibold ${r.profit >= 0 ? 'text-teal-800' : 'text-amber-800'}`}>
          {formatMoney(r.profit)}
        </span>
      ),
    },
  ];

  const invalidRange = to < from;

  return (
    <ReportPage>
      <PageHeader
        title="Machine profit"
        description="Per machine: hours, money earned from work, costs, and what’s left — for the dates you choose."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Machine profit' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">Field cycle (optional)</label>
            <select
              value={projectId}
              onChange={(e) => {
                setProjectId(e.target.value);
                setCropCycleId('');
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All fields</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">Crop filter</label>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
              disabled={!projectId}
            >
              <option value="">All activity</option>
              {projectCropId ? <option value={projectCropId}>This crop season</option> : null}
            </select>
          </div>
        </div>
        {invalidRange && <p className="text-sm text-red-600">End date must be on or after start date.</p>}
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
          {error instanceof Error ? error.message : 'Could not load data.'}
        </div>
      )}

      {invalidRange ? null : isLoading ? (
        <div className="flex justify-center py-16">
          <LoadingSpinner size="lg" />
        </div>
      ) : (
        <>
          {totals && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              <div className="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <p className="text-xs text-gray-500">Total hours</p>
                <p className="text-lg font-semibold tabular-nums">{totals.hours.toFixed(2)}</p>
              </div>
              <div className="rounded-xl border border-emerald-100 bg-emerald-50/70 p-4 shadow-sm">
                <p className="text-xs text-emerald-900/80">Money in</p>
                <p className="text-lg font-semibold tabular-nums text-emerald-950">{formatMoney(totals.revenue)}</p>
              </div>
              <div className="rounded-xl border border-rose-100 bg-rose-50/70 p-4 shadow-sm">
                <p className="text-xs text-rose-900/80">Money out</p>
                <p className="text-lg font-semibold tabular-nums text-rose-950">{formatMoney(totals.cost)}</p>
              </div>
              <div
                className={`rounded-xl border p-4 shadow-sm ${
                  totals.profit >= 0 ? 'border-teal-200 bg-teal-50/80' : 'border-amber-200 bg-amber-50/80'
                }`}
              >
                <p className="text-xs text-gray-700">What’s left</p>
                <p className={`text-lg font-semibold tabular-nums ${totals.profit >= 0 ? 'text-teal-900' : 'text-amber-900'}`}>
                  {formatMoney(totals.profit)}
                </p>
              </div>
            </div>
          )}

          <ReportSectionCard>
            <div className="px-6 py-4 border-b border-gray-100">
              <h3 className="text-sm font-semibold text-gray-900">By machine</h3>
            </div>
            <div className="p-6 pt-4">
              {tableRows.length === 0 ? (
                <ReportEmptyStateCard message={EMPTY_COPY.noDataForPeriod} className="shadow-none border-0" />
              ) : (
                <div className="overflow-x-auto -mx-1">
                  <DataTable data={tableRows} columns={columns} />
                </div>
              )}
            </div>
          </ReportSectionCard>
        </>
      )}
    </ReportPage>
  );
}
