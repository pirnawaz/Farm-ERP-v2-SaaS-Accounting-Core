import { useEffect, useMemo, useState } from 'react';
import {
  buildHariStatementExportFilename,
  downloadReportBlob,
  projectPartyEconomicsExportPath,
} from '../../utils/reportExportDownload';
import { Link, useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useParties } from '../../hooks/useParties';
import { useProject, useProjects } from '../../hooks/useProjects';
import { useProjectPartyEconomicsReport } from '../../hooks/useReports';
import { ReportEmptyStateCard, ReportErrorState, ReportPage, ReportSectionCard } from '../../components/report';

function today(): string {
  return new Date().toISOString().split('T')[0];
}

function positionLabel(p: string | null | undefined): string {
  if (p === 'PAYABLE') return 'Net payable (Hari owes)';
  if (p === 'RECEIVABLE') return 'Net receivable (Hari is owed)';
  if (p === 'SETTLED') return 'Settled (zero)';
  return p ? String(p) : '—';
}

export default function ProjectPartyEconomicsPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: parties = [] } = useParties();
  const { data: projects } = useProjects();

  const [projectId, setProjectId] = useState('');
  const [partyId, setPartyId] = useState('');
  const [upToDate, setUpToDate] = useState(today);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    const p = searchParams.get('project_id');
    const y = searchParams.get('party_id');
    const u = searchParams.get('up_to_date');
    if (p) setProjectId(p);
    if (y) setPartyId(y);
    if (u) setUpToDate(u);
  }, [searchParams]);

  const { data: projectMeta } = useProject(projectId || '');

  useEffect(() => {
    if (partyId) return;
    if (projectMeta?.party_id) setPartyId(projectMeta.party_id);
  }, [projectMeta?.party_id, partyId]);

  const params = useMemo(
    () => ({
      project_id: projectId,
      party_id: partyId,
      up_to_date: upToDate,
    }),
    [projectId, partyId, upToDate]
  );

  const { data, isLoading, error, isFetching } = useProjectPartyEconomicsReport(params, {
    enabled: !!projectId && !!partyId && !!upToDate,
  });

  const explanation = data?.party_economics_explanation;
  const hari = data?.hari_settlement_preview;
  const terms = data?.settlement_terms;
  const partyName = parties.find((x) => x.id === partyId)?.name;
  const projectName = projects?.find((p) => p.id === projectId)?.name;
  const isNonHariParty =
    !!projectMeta?.party_id && !!partyId && partyId !== projectMeta.party_id;
  const exportReady = !!projectId && !!partyId && !!upToDate;

  return (
    <ReportPage>
      <PageHeader
        title="Hari statement"
        description="Party economics: up-to-date settlement-style readout for one party on this project (same server preview as Settlement). For a calendar-range view, use Who bears what (period)."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Hari statement' },
        ]}
      />

      <ReportSectionCard>
        <div className="px-6 py-3 border-b border-gray-100">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
        </div>
        <div className="p-6 pt-4 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
          <label className="block">
            <span className="text-gray-600 font-medium">Project</span>
            <select
              value={projectId}
              onChange={(e) => {
                setProjectId(e.target.value);
                setPartyId('');
              }}
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
            <span className="text-gray-600 font-medium">Party</span>
            <select
              value={partyId}
              onChange={(e) => setPartyId(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            >
              <option value="">Select party</option>
              {parties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
          {isNonHariParty && (
            <p className="col-span-1 md:col-span-3 text-xs text-amber-900 bg-amber-50/90 border border-amber-200 rounded-md px-3 py-2">
              Detailed settlement-style figures (gross, deductions, net, kamdari) are available when the selected party
              is this project&apos;s Hari. You can still export the summary below for other parties.
            </p>
          )}
          <label className="block">
            <span className="text-gray-600 font-medium">Up to date</span>
            <input
              type="date"
              value={upToDate}
              onChange={(e) => setUpToDate(e.target.value)}
              className="mt-1 w-full border rounded-md px-3 py-2 border-gray-300"
            />
          </label>
        </div>
        <p className="text-xs text-gray-500 mt-3 px-6">
          Open from <strong>Project</strong> or <strong>Settlement</strong> to pre-fill. The full Hari settlement
          breakdown (gross, deductions, net, kamdari) is returned when the selected party is this project&apos;s Hari.
        </p>
        <p className="text-xs text-gray-500 mt-1 px-6 pb-6">
          For a <strong>date-range responsibility</strong> view instead, use{' '}
          <Link
            to={
              projectId
                ? `/app/reports/project-responsibility?project_id=${encodeURIComponent(projectId)}&from=${encodeURIComponent(`${new Date().getFullYear()}-01-01`)}&to=${encodeURIComponent(upToDate)}`
                : '/app/reports/project-responsibility'
            }
            className="text-[#1F6F5C] font-medium hover:underline"
          >
            Who bears what (period)
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
                    projectPartyEconomicsExportPath('pdf', {
                      project_id: projectId,
                      party_id: partyId,
                      up_to_date: upToDate,
                    }),
                    buildHariStatementExportFilename(projectId, projectName, upToDate, 'pdf')
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
                    projectPartyEconomicsExportPath('csv', {
                      project_id: projectId,
                      party_id: partyId,
                      up_to_date: upToDate,
                    }),
                    buildHariStatementExportFilename(projectId, projectName, upToDate, 'csv')
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
            <p className="text-xs text-gray-500 mt-2">Select project, party, and up-to date to export.</p>
          )}
        </div>
      </ReportSectionCard>

      {(!projectId || !partyId) && (
        <ReportEmptyStateCard message="Select a project and party, and an up-to date, to load party economics." />
      )}

      {projectId && partyId && error && <ReportErrorState error={error} />}

      {projectId && partyId && !error && (isLoading || isFetching) && (
        <div className="text-sm text-gray-600 py-6">Loading…</div>
      )}

      {data && !isLoading && !error && (
        <>
          <ReportSectionCard>
            <div className="px-6 py-3 border-b border-gray-100">
              <h2 className="text-sm font-semibold text-gray-900">Statement header</h2>
            </div>
            <div className="p-6 pt-4">
            <dl className="text-sm space-y-1">
              <div className="flex justify-between gap-4">
                <dt className="text-gray-500">Party</dt>
                <dd className="font-medium text-right">{partyName ?? data.party_id}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-gray-500">Project Hari?</dt>
                <dd className="font-medium text-right">{data.is_project_hari_party ? 'Yes' : 'No'}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-gray-500">As of</dt>
                <dd className="font-medium text-right tabular-nums">{data.up_to_date}</dd>
              </div>
            </dl>
            {!data.is_project_hari_party && (
              <p className="text-sm text-gray-600 mt-3">
                For parties other than this project&apos;s Hari, the server still returns the same project-level
                responsibility explanation below. There is no separate per-party settlement slice in this response yet.
              </p>
            )}
            </div>
          </ReportSectionCard>

          {hari && data.is_project_hari_party && (
            <ReportSectionCard>
              <div className="px-6 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-900">Hari — settlement preview figures (server)</h2>
              </div>
              <div className="p-6 pt-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                <div>
                  <div className="text-gray-500 text-xs">Gross share</div>
                  <div className="text-lg font-semibold tabular-nums">{formatMoney(String(hari.hari_gross ?? 0))}</div>
                </div>
                <div>
                  <div className="text-gray-500 text-xs">Hari-only deductions</div>
                  <div className="text-lg font-semibold tabular-nums">
                    {formatMoney(String(hari.hari_only_deductions ?? 0))}
                  </div>
                </div>
                <div>
                  <div className="text-gray-500 text-xs">Net after deductions</div>
                  <div className="text-lg font-semibold tabular-nums">{formatMoney(String(hari.hari_net ?? 0))}</div>
                </div>
                <div>
                  <div className="text-gray-500 text-xs">Position</div>
                  <div className="text-lg font-semibold">{positionLabel(hari.hari_position)}</div>
                </div>
                <div>
                  <div className="text-gray-500 text-xs">Kamdari</div>
                  <div className="text-lg font-semibold tabular-nums">
                    {formatMoney(String(hari.kamdari_amount ?? 0))}
                  </div>
                </div>
                <div>
                  <div className="text-gray-500 text-xs">Landlord gross</div>
                  <div className="text-lg font-semibold tabular-nums">
                    {formatMoney(String(hari.landlord_gross ?? 0))}
                  </div>
                </div>
              </div>
              <p className="text-xs text-gray-500 mt-4">
                These numbers are taken from the settlement preview the API runs for this project and date — not
                recomputed in the browser.
              </p>
              </div>
            </ReportSectionCard>
          )}

          {terms && (
            <ReportSectionCard>
              <div className="px-6 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-900">Settlement terms (basis)</h2>
              </div>
              <div className="p-6 pt-4">
              {terms.resolution_error ? (
                <p className="text-sm text-red-700">{terms.resolution_error}</p>
              ) : (
                <dl className="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                  <div>
                    <dt className="text-gray-500">Resolution</dt>
                    <dd className="font-medium">
                      {terms.resolution_source === 'agreement'
                        ? 'Agreement (primary)'
                        : terms.resolution_source === 'project_rule'
                          ? 'Legacy project rules (fallback)'
                          : terms.resolution_source ?? '—'}
                    </dd>
                  </div>
                  {terms.profit_split_landlord_pct != null && (
                    <div>
                      <dt className="text-gray-500">Split (landlord / Hari)</dt>
                      <dd className="font-medium tabular-nums">
                        {terms.profit_split_landlord_pct}% / {terms.profit_split_hari_pct ?? '—'}%
                      </dd>
                    </div>
                  )}
                </dl>
              )}
              </div>
            </ReportSectionCard>
          )}

          {explanation && (
            <ReportSectionCard>
              <div className="px-6 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-900">Who bears what — responsibility summary</h2>
              </div>
              <div className="p-6 pt-4">
              {explanation.summary_lines && Object.keys(explanation.summary_lines).length > 0 && (
                <ul className="mb-4 space-y-2 text-sm border-b border-gray-100 pb-4">
                  {Object.entries(explanation.summary_lines).map(([label, amt]) => (
                    <li key={label} className="flex justify-between gap-4">
                      <span className="text-gray-700">{label}</span>
                      <span className="font-medium tabular-nums">{formatMoney(String(amt))}</span>
                    </li>
                  ))}
                </ul>
              )}
              {explanation.recoverability && (
                <dl className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm mb-4">
                  <div>
                    <dt className="text-gray-500">In shared pool (settlement base)</dt>
                    <dd className="font-medium tabular-nums">
                      {formatMoney(String(explanation.recoverability.included_in_shared_pool_for_settlement ?? 0))}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Hari-only (after split)</dt>
                    <dd className="font-medium tabular-nums">
                      {formatMoney(String(explanation.recoverability.hari_borne_after_split ?? 0))}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-gray-500">Landlord / owner-only</dt>
                    <dd className="font-medium tabular-nums">
                      {formatMoney(String(explanation.recoverability.owner_borne_not_in_pool ?? 0))}
                    </dd>
                  </div>
                  {(explanation.recoverability.shared_scope_other_amounts ?? 0) > 0 && (
                    <div className="sm:col-span-2">
                      <dt className="text-gray-500">Other shared-scope</dt>
                      <dd className="font-medium tabular-nums">
                        {formatMoney(String(explanation.recoverability.shared_scope_other_amounts ?? 0))}
                      </dd>
                      {explanation.recoverability.shared_scope_other_note ? (
                        <p className="text-xs text-gray-600 mt-1">{explanation.recoverability.shared_scope_other_note}</p>
                      ) : null}
                    </div>
                  )}
                </dl>
              )}
              {explanation.legacy_unscoped_note ? (
                <p className="text-xs text-amber-800">{explanation.legacy_unscoped_note}</p>
              ) : null}
              </div>
            </ReportSectionCard>
          )}
        </>
      )}
    </ReportPage>
  );
}
