import { useEffect, useMemo, useState } from 'react';
import {
  buildResponsibilityExportFilename,
  downloadReportBlob,
  projectResponsibilityExportPath,
} from '../../utils/reportExportDownload';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useProjectResponsibilityReport } from '../../hooks/useReports';
import { ReportEmptyStateCard, ReportErrorState, ReportPage, ReportSectionCard } from '../../components/report';
import type { ProjectResponsibilityBuckets, ProjectResponsibilityReport } from '../../types';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

function bucketAmount(
  buckets: ProjectResponsibilityReport['buckets'],
  key: keyof ProjectResponsibilityBuckets
): number {
  if (!buckets || Array.isArray(buckets)) return 0;
  return Number((buckets as ProjectResponsibilityBuckets)[key] ?? 0);
}

function settlementTermsLabel(source: string | null | undefined): string {
  if (!source) return 'Not resolved';
  if (source === 'agreement') return 'Agreement (primary)';
  if (source === 'project_rule') return 'Legacy project rules (fallback)';
  return String(source);
}

export default function ProjectResponsibilityReportPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: projects } = useProjects();
  const { data: cropCycles = [] } = useCropCycles();

  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [cropCycleId, setCropCycleId] = useState('');
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const p = searchParams.get('project_id');
    const f = searchParams.get('from');
    const t = searchParams.get('to');
    const c = searchParams.get('crop_cycle_id');
    if (p) setProjectId(p);
    if (f) setFrom(f);
    if (t) setTo(t);
    if (c) setCropCycleId(c);
  }, [searchParams]);

  const params = useMemo(
    () => ({
      project_id: projectId,
      from,
      to,
      ...(cropCycleId ? { crop_cycle_id: cropCycleId } : {}),
    }),
    [projectId, from, to, cropCycleId]
  );

  const { data, isLoading, error, isFetching } = useProjectResponsibilityReport(params, {
    enabled: !!projectId && !!from && !!to,
  });

  const shared = data ? bucketAmount(data.buckets, 'settlement_shared_pool_costs') : 0;
  const hariOnly = data ? bucketAmount(data.buckets, 'hari_only_costs') : 0;
  const landlordOnly = data ? bucketAmount(data.buckets, 'landlord_only_costs') : 0;
  const sharedOther = data ? bucketAmount(data.buckets, 'shared_scope_non_pool_share_positive') : 0;
  const legacyUnscoped = data ? bucketAmount(data.buckets, 'legacy_unscoped_amount') : 0;

  const byEff = data?.by_effective_responsibility ?? {};
  const topTypes = data?.top_allocation_types ?? [];
  const terms = data?.settlement_terms;
  const projectName = projects?.find((p) => p.id === projectId)?.name;
  const exportReady = !!projectId && !!from && !!to;

  return (
    <ReportPage>
      <PageHeader
        title="Who bears what (period)"
        description="Period-based view: costs in this date range that count toward the field cycle, grouped by who carries them in settlement. Same basis as Field Cycle P&L posting groups — not the same as a single “up to” settlement preview."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Who bears what (period)' },
        ]}
      />

      <ReportSectionCard>
        <div className="px-6 py-3 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
        </div>
        <div className="p-6 pt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
          <label className="block">
            <span className="text-gray-600 font-medium">Project</span>
            <select
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            >
              <option value="">Select project</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="text-gray-600 font-medium">From</span>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            />
          </label>
          <label className="block">
            <span className="text-gray-600 font-medium">To</span>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            />
          </label>
          <label className="block">
            <span className="text-gray-600 font-medium">Crop cycle (optional)</span>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            >
              <option value="">All / tenant default</option>
              {cropCycles.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="text-xs text-gray-500 mt-3 px-6 pb-6">
          For an <strong>up-to-date settlement-style</strong> view (one “as of” date), use{' '}
          <Link to="/app/settlement" className="text-[#1F6F5C] font-medium hover:underline">
            Settlement
          </Link>{' '}
          or{' '}
          <Link
            to={projectId ? `/app/reports/project-party-economics?project_id=${encodeURIComponent(projectId)}&up_to_date=${encodeURIComponent(to)}` : '/app/reports/project-party-economics'}
            className="text-[#1F6F5C] font-medium hover:underline"
          >
            Hari statement
          </Link>
          .
        </p>
        <div className="px-6 pb-6 border-t border-gray-100 pt-4 bg-[#fafafa]">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700 mb-2">Export</h3>
          <div className="flex flex-wrap gap-2 items-center">
            <button
              type="button"
              disabled={!exportReady || exporting}
              onClick={async () => {
                if (!exportReady) return;
                setExporting(true);
                try {
                  await downloadReportBlob(
                    projectResponsibilityExportPath('pdf', {
                      project_id: projectId,
                      from,
                      to,
                      crop_cycle_id: cropCycleId || undefined,
                    }),
                    buildResponsibilityExportFilename(projectId, projectName, from, to, 'pdf')
                  );
                } finally {
                  setExporting(false);
                }
              }}
              className="px-3 py-1.5 text-sm rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Export PDF
            </button>
            <button
              type="button"
              disabled={!exportReady || exporting}
              onClick={async () => {
                if (!exportReady) return;
                setExporting(true);
                try {
                  await downloadReportBlob(
                    projectResponsibilityExportPath('csv', {
                      project_id: projectId,
                      from,
                      to,
                      crop_cycle_id: cropCycleId || undefined,
                    }),
                    buildResponsibilityExportFilename(projectId, projectName, from, to, 'csv')
                  );
                } finally {
                  setExporting(false);
                }
              }}
              className="px-3 py-1.5 text-sm rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              Export CSV
            </button>
          </div>
          {!exportReady && (
            <p className="text-xs text-gray-500 mt-2">Select project and dates to export.</p>
          )}
        </div>
      </ReportSectionCard>

      {!projectId && (
        <ReportEmptyStateCard message="Choose a project and date range to see who bears which costs for that period." />
      )}

      {projectId && error && <ReportErrorState error={error} />}

      {projectId && !error && (isLoading || isFetching) && (
        <div className="text-sm text-gray-600 py-6">Loading report…</div>
      )}

      {projectId && data && !isLoading && !error && (
        <>
          <ReportSectionCard>
            <div className="px-6 py-3 border-b border-gray-100">
              <h2 className="text-sm font-semibold text-gray-900">Headline totals (recoverable through settlement)</h2>
            </div>
            <div className="p-6 pt-4">
            <p className="text-xs text-gray-500 mb-4">
              {data.posting_groups_count} posting group(s) in range. Amounts come from posted allocations — nothing is recalculated here.
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <div className="rounded-lg border border-gray-200 p-4 bg-[#E6ECEA]/40">
                <div className="text-xs font-medium text-gray-600">Shared costs (pool)</div>
                <div className="text-xl font-semibold tabular-nums mt-1">{formatMoney(String(shared))}</div>
              </div>
              <div className="rounded-lg border border-gray-200 p-4 bg-[#E6ECEA]/40">
                <div className="text-xs font-medium text-gray-600">Hari-only</div>
                <div className="text-xl font-semibold tabular-nums mt-1">{formatMoney(String(hariOnly))}</div>
              </div>
              <div className="rounded-lg border border-gray-200 p-4 bg-[#E6ECEA]/40">
                <div className="text-xs font-medium text-gray-600">Landlord-only</div>
                <div className="text-xl font-semibold tabular-nums mt-1">{formatMoney(String(landlordOnly))}</div>
              </div>
              <div className="rounded-lg border border-gray-200 p-4 bg-amber-50/80">
                <div className="text-xs font-medium text-gray-600">Other shared-scope (positive)</div>
                <div className="text-xl font-semibold tabular-nums mt-1">{formatMoney(String(sharedOther))}</div>
                {sharedOther > 0.005 ? (
                  <p className="text-xs text-gray-600 mt-2">
                    Other shared-scope charges can appear in the ledger; the settlement pool still uses the same pool rules
                    as the live settlement engine — see settlement preview for the up-to-date split.
                  </p>
                ) : null}
              </div>
            </div>
            {legacyUnscoped > 0.005 ? (
              <p className="text-xs text-amber-800 mt-3">
                Unscoped legacy allocations: {formatMoney(String(legacyUnscoped))} — review source postings if this is
                material.
              </p>
            ) : null}
            </div>
          </ReportSectionCard>

          {terms && (
            <ReportSectionCard>
              <div className="px-6 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-900">Settlement basis (terms in effect for this project)</h2>
              </div>
              <div className="p-6 pt-4">
              {terms.resolution_error ? (
                <p className="text-sm text-red-700">{terms.resolution_error}</p>
              ) : (
                <dl className="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                  <div>
                    <dt className="text-gray-500">Source</dt>
                    <dd className="font-medium">{settlementTermsLabel(terms.resolution_source)}</dd>
                  </div>
                  {terms.profit_split_landlord_pct != null && terms.profit_split_hari_pct != null && (
                    <div>
                      <dt className="text-gray-500">Profit split (landlord / Hari)</dt>
                      <dd className="font-medium tabular-nums">
                        {terms.profit_split_landlord_pct}% / {terms.profit_split_hari_pct}%
                      </dd>
                    </div>
                  )}
                  {terms.kamdari_pct != null && (
                    <div>
                      <dt className="text-gray-500">Kamdari %</dt>
                      <dd className="font-medium">{terms.kamdari_pct}%</dd>
                    </div>
                  )}
                  {terms.kamdari_order && (
                    <div>
                      <dt className="text-gray-500">Kamdari order</dt>
                      <dd className="font-medium">{terms.kamdari_order}</dd>
                    </div>
                  )}
                </dl>
              )}
              </div>
            </ReportSectionCard>
          )}

          <ReportSectionCard>
            <div className="px-6 py-3 border-b border-gray-100">
              <h2 className="text-sm font-semibold text-gray-900">Effective responsibility buckets</h2>
            </div>
            <div className="p-6 pt-4">
            {Object.keys(byEff).length === 0 ? (
              <p className="text-sm text-gray-600">No non-zero allocation rows in this period.</p>
            ) : (
              <ul className="divide-y divide-gray-100 text-sm">
                {Object.entries(byEff)
                  .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]))
                  .map(([scope, amt]) => (
                    <li key={scope} className="flex justify-between py-2">
                      <span className="text-gray-700">{scope}</span>
                      <span className="font-medium tabular-nums">{formatMoney(String(amt))}</span>
                    </li>
                  ))}
              </ul>
            )}
            </div>
          </ReportSectionCard>

          {topTypes.length > 0 && (
            <ReportSectionCard>
              <div className="px-6 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-900">Top allocation types (by amount)</h2>
              </div>
              <div className="p-6 pt-4">
              <ul className="divide-y divide-gray-100 text-sm max-h-72 overflow-y-auto">
                {topTypes.map((row) => (
                  <li key={row.type} className="flex justify-between py-2">
                    <span className="text-gray-700 font-mono text-xs">{row.type}</span>
                    <span className="font-medium tabular-nums">{formatMoney(String(row.amount))}</span>
                  </li>
                ))}
              </ul>
              </div>
            </ReportSectionCard>
          )}
        </>
      )}
    </ReportPage>
  );
}
