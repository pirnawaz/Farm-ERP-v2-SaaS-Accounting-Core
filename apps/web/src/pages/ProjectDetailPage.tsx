import { Link } from 'react-router-dom';
import { useParams } from 'react-router-dom';
import { useMemo, useState } from 'react';
import { useProject } from '../hooks/useProjects';
import { useModules } from '../contexts/ModulesContext';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { SetupStatusCard } from '../components/SetupStatusCard';
import { isSetupComplete } from '../components/setupSemantics';
import {
  buildHariStatementExportFilename,
  buildSettlementReviewExportFilename,
  downloadReportBlob,
  projectPartyEconomicsExportPath,
  projectSettlementReviewExportPath,
} from '../utils/reportExportDownload';

function yearStart(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: project, isLoading } = useProject(id || '');
  const { isModuleEnabled } = useModules();
  const showMachinery = isModuleEnabled('machinery');
  const showFinancials = isModuleEnabled('reports') && isModuleEnabled('projects_crop_cycles');
  const showSettlementPreview = isModuleEnabled('settlements');
  const reportPeriod = useMemo(() => ({ from: yearStart(), to: today() }), []);
  const [financialExporting, setFinancialExporting] = useState(false);

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!project) {
    return <div>Project not found</div>;
  }

  const allocation = project.land_allocation;
  const agreementAllocation = project.agreement_allocation;
  const inferredParcel =
    allocation?.land_parcel ??
    agreementAllocation?.land_parcel ??
    null;
  const allocatedArea =
    allocation?.allocated_acres ??
    agreementAllocation?.allocated_area ??
    null;
  const areaUom = agreementAllocation?.area_uom || 'ACRE';

  const hasLandAllocation = !!(project.land_allocation_id || project.land_allocation);
  const hasFieldBlock = !!(project.field_block_id || project.field_block);
  const hasAgreement = !!(project.agreement_id || project.agreement);
  const hasAgreementAllocation = !!(project.agreement_allocation_id || project.agreement_allocation);
  const setupComplete = isSetupComplete(project);

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/projects" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Projects
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">{project.name}</h1>
      </div>

      <div className="mb-6">
        <SetupStatusCard
          title="Setup status"
          subtitle="Quick view of the Field Cycle setup chain."
          actions={
            !setupComplete ? (
              <Link
                to={`/app/projects/setup?project_id=${encodeURIComponent(project.id)}`}
                className="inline-flex items-center justify-center rounded-md bg-[#1F6F5C] px-3 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
              >
                Complete setup
              </Link>
            ) : null
          }
          rows={[
            { label: 'Crop cycle', value: project.crop_cycle?.name || '—' },
            { label: 'Parcel', value: inferredParcel?.name || '—' },
            {
              label: 'Allocated area',
              value: allocatedArea ? `${allocatedArea} ${allocation ? 'acres' : areaUom}` : '—',
            },
            { label: 'Land allocation', present: hasLandAllocation, presentLabel: 'Present', missingLabel: 'Missing' },
            { label: 'Field block', present: hasFieldBlock, presentLabel: 'Present', missingLabel: 'Missing' },
            { label: 'Agreement', present: hasAgreement, presentLabel: 'Present', missingLabel: 'Missing' },
            {
              label: 'Agreement allocation',
              present: hasAgreementAllocation,
              presentLabel: 'Present',
              missingLabel: 'Missing',
            },
          ]}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Project Information</h2>
          <dl className="space-y-2">
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{project.crop_cycle?.name || 'N/A'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">HARI</dt>
              <dd className="text-sm text-gray-900">{project.party?.name || 'N/A'}</dd>
            </div>
            <div>
              <dt className="text-sm font-medium text-gray-500">Status</dt>
              <dd className="text-sm text-gray-900">{project.status}</dd>
            </div>
            {project.agreement_id && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Agreement</dt>
                <dd className="text-sm text-gray-900">
                  <Link
                    to={`/app/crop-ops/agreements/${project.agreement_id}`}
                    className="text-[#1F6F5C] hover:underline"
                  >
                    View linked agreement
                  </Link>
                  <span className="block text-xs text-gray-500 mt-1">
                    Settlement terms are expected on the agreement for this link.
                  </span>
                </dd>
              </div>
            )}
            {project.settlement_resolution &&
              project.settlement_resolution.resolution_source &&
              project.settlement_resolution.resolution_source !== 'unresolved' && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Settlement terms source</dt>
                  <dd className="text-sm text-gray-700">
                    {project.settlement_resolution.resolution_source === 'agreement'
                      ? 'Agreement (primary)'
                      : 'Legacy project rules (fallback)'}
                  </dd>
                </div>
              )}
            {project.land_allocation && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Allocated Acres</dt>
                <dd className="text-sm text-gray-900">{project.land_allocation.allocated_acres}</dd>
              </div>
            )}
            {project.agreement_allocation && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Agreement allocation</dt>
                <dd className="text-sm text-gray-900">
                  {project.agreement_allocation.allocated_area} {project.agreement_allocation.area_uom || 'ACRE'} on{' '}
                  {project.agreement_allocation.land_parcel?.name ?? 'parcel'}
                </dd>
              </div>
            )}
            {!project.agreement_allocation_id && project.project_rule && (
              <div>
                <dt className="text-sm font-medium text-gray-500">Settlement rules</dt>
                <dd className="text-sm text-gray-600">Legacy project rules (no agreement link)</dd>
              </div>
            )}
          </dl>
        </div>

        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Quick Links</h2>
          <div className="space-y-2">
            {showSettlementPreview && (
              <Link
                to={`/app/settlement?project_id=${project.id}`}
                className="block px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-center"
              >
                Settlement preview
              </Link>
            )}
            <Link
              to={`/app/reports/project-responsibility?project_id=${encodeURIComponent(project.id)}&from=${encodeURIComponent(reportPeriod.from)}&to=${encodeURIComponent(reportPeriod.to)}${project.crop_cycle_id ? `&crop_cycle_id=${encodeURIComponent(project.crop_cycle_id)}` : ''}`}
              className="block px-4 py-2 border border-gray-300 text-gray-800 rounded-md hover:bg-gray-50 text-center text-sm"
            >
              Who bears what (period)
            </Link>
            <Link
              to={`/app/reports/project-party-economics?project_id=${encodeURIComponent(project.id)}&party_id=${encodeURIComponent(project.party_id)}&up_to_date=${encodeURIComponent(reportPeriod.to)}`}
              className="block px-4 py-2 border border-gray-300 text-gray-800 rounded-md hover:bg-gray-50 text-center text-sm"
            >
              Hari statement
            </Link>
            <Link
              to={`/app/projects/${project.id}/rules`}
              className="block px-4 py-2 border border-gray-300 text-gray-800 rounded-md hover:bg-gray-50 text-center text-sm"
            >
              Legacy project rules (fallback)
            </Link>
            <Link
              to={`/app/transactions?project_id=${project.id}`}
              className="block px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 text-center"
            >
              View Transactions
            </Link>
          </div>
        </div>

        {showFinancials && (
          <div className="bg-white rounded-lg shadow p-6 lg:col-span-2">
            <h2 className="text-lg font-medium text-gray-900 mb-1">Financials</h2>
            <p className="text-xs text-gray-500 mb-4">
              Settlement preview, shareable settlement review pack, and period responsibility — same data as the
              reports and settlement screens.
            </p>
            <div className="flex flex-col sm:flex-row sm:flex-wrap gap-3">
              {showSettlementPreview && (
                <Link
                  to={`/app/settlement?project_id=${project.id}`}
                  className="inline-flex justify-center px-4 py-2 rounded-md border border-gray-300 text-[#1F6F5C] font-medium text-sm hover:bg-gray-50"
                >
                  View settlement preview
                </Link>
              )}
              <Link
                to={`/app/reports/project-responsibility?project_id=${encodeURIComponent(project.id)}&from=${encodeURIComponent(reportPeriod.from)}&to=${encodeURIComponent(reportPeriod.to)}${project.crop_cycle_id ? `&crop_cycle_id=${encodeURIComponent(project.crop_cycle_id)}` : ''}`}
                className="inline-flex justify-center px-4 py-2 rounded-md border border-gray-300 text-[#1F6F5C] font-medium text-sm hover:bg-gray-50"
              >
                View who bears what (period)
              </Link>
              <Link
                to={`/app/reports/project-party-economics?project_id=${encodeURIComponent(project.id)}&party_id=${encodeURIComponent(project.party_id)}&up_to_date=${encodeURIComponent(reportPeriod.to)}`}
                className="inline-flex justify-center px-4 py-2 rounded-md border border-gray-300 text-[#1F6F5C] font-medium text-sm hover:bg-gray-50"
              >
                View Hari statement
              </Link>
            </div>
            <div className="mt-4 rounded-md border border-slate-200 bg-slate-50/80 p-3">
              <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-700 mb-2">Export settlement review pack</h3>
              <p className="text-xs text-gray-500 mb-2">
                Uses today as &quot;Up to date&quot; and {reportPeriod.from} → {reportPeriod.to} for the period slice in
                the pack (same defaults as opening Settlement without preview).
              </p>
              <div className="flex flex-wrap gap-2 items-center">
                <span className="text-xs text-gray-600 shrink-0">Settlement review pack:</span>
                <button
                  type="button"
                  disabled={financialExporting}
                  onClick={async () => {
                    setFinancialExporting(true);
                    try {
                      await downloadReportBlob(
                        projectSettlementReviewExportPath('pdf', {
                          project_id: project.id,
                          up_to_date: reportPeriod.to,
                          responsibility_from: reportPeriod.from,
                          responsibility_to: reportPeriod.to,
                        }),
                        buildSettlementReviewExportFilename(project.id, project.name, reportPeriod.to, 'pdf')
                      );
                    } finally {
                      setFinancialExporting(false);
                    }
                  }}
                  className="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50"
                >
                  Export PDF
                </button>
                <button
                  type="button"
                  disabled={financialExporting}
                  onClick={async () => {
                    setFinancialExporting(true);
                    try {
                      await downloadReportBlob(
                        projectSettlementReviewExportPath('csv', {
                          project_id: project.id,
                          up_to_date: reportPeriod.to,
                          responsibility_from: reportPeriod.from,
                          responsibility_to: reportPeriod.to,
                        }),
                        buildSettlementReviewExportFilename(project.id, project.name, reportPeriod.to, 'csv')
                      );
                    } finally {
                      setFinancialExporting(false);
                    }
                  }}
                  className="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50"
                >
                  Export CSV
                </button>
                <span className="text-xs text-gray-600 w-full pt-2 border-t border-slate-200 mt-1 shrink-0">
                  Hari statement:
                </span>
                <button
                  type="button"
                  disabled={financialExporting}
                  onClick={async () => {
                    setFinancialExporting(true);
                    try {
                      await downloadReportBlob(
                        projectPartyEconomicsExportPath('pdf', {
                          project_id: project.id,
                          party_id: project.party_id,
                          up_to_date: reportPeriod.to,
                        }),
                        buildHariStatementExportFilename(project.id, project.name, reportPeriod.to, 'pdf')
                      );
                    } finally {
                      setFinancialExporting(false);
                    }
                  }}
                  className="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50"
                >
                  Export PDF
                </button>
                <button
                  type="button"
                  disabled={financialExporting}
                  onClick={async () => {
                    setFinancialExporting(true);
                    try {
                      await downloadReportBlob(
                        projectPartyEconomicsExportPath('csv', {
                          project_id: project.id,
                          party_id: project.party_id,
                          up_to_date: reportPeriod.to,
                        }),
                        buildHariStatementExportFilename(project.id, project.name, reportPeriod.to, 'csv')
                      );
                    } finally {
                      setFinancialExporting(false);
                    }
                  }}
                  className="px-3 py-1.5 text-xs rounded-md border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50"
                >
                  Export CSV
                </button>
              </div>
            </div>
          </div>
        )}

        {showMachinery && (
          <div className="bg-white rounded-lg shadow p-6 lg:col-span-2">
            <h2 className="text-lg font-medium text-gray-900 mb-4">Machinery</h2>
            <p className="text-sm text-gray-500 mb-4">
              Machinery services, work logs and charges linked to this project.
            </p>
            <div className="flex flex-wrap gap-3 items-center">
              <Link
                to={`/app/machinery/services?project_id=${project.id}`}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
              >
                Machinery Services
              </Link>
              <Link
                to={`/app/machinery/work-logs?project_id=${project.id}`}
                className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
              >
                Work Logs
              </Link>
              <Link
                to={`/app/machinery/charges?project_id=${project.id}`}
                className="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700"
              >
                Charges
              </Link>
              <Link
                to={`/app/machinery/services/new?project_id=${project.id}`}
                className="px-4 py-2 border-2 border-[#1F6F5C] text-[#1F6F5C] rounded-md hover:bg-[#1F6F5C] hover:text-white"
              >
                Add Machinery Service
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
