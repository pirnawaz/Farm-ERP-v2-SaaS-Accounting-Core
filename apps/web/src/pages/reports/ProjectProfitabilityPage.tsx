import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import { useProjects, useProject } from '../../hooks/useProjects';
import { useProjectProfitability } from '../../hooks/useReports';
import { ReportEmptyStateCard, ReportPage, ReportSectionCard } from '../../components/report';
import { EMPTY_COPY } from '../../config/presentation';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

/** Simple relative bar: two segments (e.g. in vs out). */
function SplitCompareBar(props: { a: number; b: number; aLabel: string; bLabel: string; aClass?: string; bClass?: string }) {
  const { a, b, aLabel, bLabel, aClass = 'bg-emerald-500', bClass = 'bg-rose-400' } = props;
  const sum = Math.abs(a) + Math.abs(b);
  const aPct = sum > 0 ? (Math.abs(a) / sum) * 100 : 50;
  const bPct = sum > 0 ? (Math.abs(b) / sum) * 100 : 50;
  return (
    <div className="space-y-2">
      <div className="flex h-4 rounded-full overflow-hidden bg-gray-100 ring-1 ring-gray-200">
        <div className={aClass} style={{ width: `${aPct}%` }} title={aLabel} />
        <div className={bClass} style={{ width: `${bPct}%` }} title={bLabel} />
      </div>
      <div className="flex justify-between text-xs text-gray-600">
        <span>{aLabel}</span>
        <span>{bLabel}</span>
      </div>
    </div>
  );
}

export default function ProjectProfitabilityPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: projects } = useProjects();
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  /** Empty = all seasons for this field; set to project’s crop cycle to narrow. */
  const [cropCycleId, setCropCycleId] = useState('');

  useEffect(() => {
    const p = searchParams.get('project_id');
    if (p) setProjectId(p);
  }, [searchParams]);

  const { data: projectMeta } = useProject(projectId || '');
  const projectCropId = projectMeta?.crop_cycle_id ?? '';

  const filters = useMemo(
    () => ({
      project_id: projectId,
      ...(from ? { from } : {}),
      ...(to ? { to } : {}),
      ...(cropCycleId ? { crop_cycle_id: cropCycleId } : {}),
    }),
    [projectId, from, to, cropCycleId]
  );

  const { data, isLoading, error } = useProjectProfitability(filters, { enabled: !!projectId });

  const rev = data?.revenue;
  const costs = data?.costs;
  const totals = data?.totals;

  const revenueRows = [
    { label: 'Sales', value: rev?.sales ?? 0 },
    { label: 'Machinery work billed', value: rev?.machinery_income ?? 0 },
    { label: 'Harvest value (in kind)', value: rev?.in_kind_income ?? 0 },
  ];
  const costRows = [
    { label: 'Inputs & stock', value: costs?.inputs ?? 0 },
    { label: 'Labour', value: costs?.labour ?? 0 },
    { label: 'Machinery & fuel', value: costs?.machinery ?? 0 },
    { label: 'Landlord share', value: costs?.landlord ?? 0 },
  ];

  const totalIn = totals?.revenue ?? 0;
  const totalOut = totals?.cost ?? 0;
  const profit = totals?.profit ?? 0;

  return (
    <ReportPage>
      <PageHeader
        title="Field cycle profit"
        description="Money in, money out, and what’s left for the field cycle you pick — based on posted activity."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Field cycle profit' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Choose field cycle & dates</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm text-gray-700 mb-1">Field cycle</label>
            <select
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select…</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
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
            <label className="block text-sm text-gray-700 mb-1">Crop filter</label>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              disabled={!projectId}
            >
              <option value="">All activity on this field</option>
              {projectCropId ? (
                <option value={projectCropId}>This crop season only</option>
              ) : null}
            </select>
          </div>
        </div>
        <p className="text-xs text-gray-500">
          Dates limit by posting date. “This crop season” matches the field cycle’s crop season.
        </p>
      </div>

      {!projectId && (
        <ReportEmptyStateCard message="Select a field cycle to see totals." className="shadow-none border border-gray-100" />
      )}

      {projectId && error && (
        <div className="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">
          {error instanceof Error ? error.message : 'Could not load data.'}
        </div>
      )}

      {projectId && isLoading && (
        <div className="flex justify-center py-16">
          <LoadingSpinner size="lg" />
        </div>
      )}

      {projectId && !isLoading && data && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="rounded-xl border border-emerald-100 bg-emerald-50/80 p-5 shadow-sm">
              <p className="text-sm text-emerald-900/80">Money in</p>
              <p className="text-2xl font-semibold tabular-nums text-emerald-950">{formatMoney(totalIn)}</p>
            </div>
            <div className="rounded-xl border border-rose-100 bg-rose-50/80 p-5 shadow-sm">
              <p className="text-sm text-rose-900/80">Money out</p>
              <p className="text-2xl font-semibold tabular-nums text-rose-950">{formatMoney(totalOut)}</p>
            </div>
            <div
              className={`rounded-xl border p-5 shadow-sm ${
                profit >= 0 ? 'border-teal-200 bg-teal-50/90' : 'border-amber-200 bg-amber-50/90'
              }`}
            >
              <p className="text-sm text-gray-700">What’s left</p>
              <p className={`text-2xl font-semibold tabular-nums ${profit >= 0 ? 'text-teal-900' : 'text-amber-900'}`}>
                {formatMoney(profit)}
              </p>
            </div>
          </div>

          <ReportSectionCard>
            <div className="px-6 py-4 border-b border-gray-100">
              <h3 className="text-sm font-semibold text-gray-900">Compare at a glance</h3>
            </div>
            <div className="p-6">
              <SplitCompareBar a={totalIn} b={totalOut} aLabel="In" bLabel="Out" />
            </div>
          </ReportSectionCard>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <ReportSectionCard>
              <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">Where the money came from</h3>
              </div>
              <ul className="divide-y divide-gray-100 px-6 py-2">
                {revenueRows.map((row) => (
                  <li key={row.label} className="flex justify-between py-3 text-sm">
                    <span className="text-gray-700">{row.label}</span>
                    <span className="font-medium tabular-nums text-gray-900">{formatMoney(row.value)}</span>
                  </li>
                ))}
              </ul>
            </ReportSectionCard>
            <ReportSectionCard>
              <div className="px-6 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-900">Where it went</h3>
              </div>
              <ul className="divide-y divide-gray-100 px-6 py-2">
                {costRows.map((row) => (
                  <li key={row.label} className="flex justify-between py-3 text-sm">
                    <span className="text-gray-700">{row.label}</span>
                    <span className="font-medium tabular-nums text-gray-900">{formatMoney(row.value)}</span>
                  </li>
                ))}
              </ul>
            </ReportSectionCard>
          </div>

          {totalIn === 0 && totalOut === 0 && (
            <ReportEmptyStateCard message={EMPTY_COPY.noDataForPeriod} className="shadow-none border border-gray-100" />
          )}
        </>
      )}
    </ReportPage>
  );
}
