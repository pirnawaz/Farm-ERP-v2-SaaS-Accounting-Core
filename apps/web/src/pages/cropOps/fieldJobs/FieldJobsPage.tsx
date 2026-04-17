import { useMemo, useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { useFieldJobs } from '../../../hooks/useFieldJobs';
import { useCropCycles } from '../../../hooks/useCropCycles';
import { useProjects } from '../../../hooks/useProjects';
import { DataTable, type Column } from '../../../components/DataTable';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { PageHeader } from '../../../components/PageHeader';
import { useFormatting } from '../../../hooks/useFormatting';
import { useRole } from '../../../hooks/useRole';
import { PostingStatusBadge } from '../../../utils/postingStatusDisplay';
import type { FieldJob } from '../../../types';
import { PrimaryWorkflowBanner } from '../../../components/workflow/PrimaryWorkflowBanner';

export default function FieldJobsPage() {
  const [status, setStatus] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const filters = useMemo(
    () => ({
      status: status || undefined,
      crop_cycle_id: cropCycleId || undefined,
      project_id: projectId || undefined,
      from: from || undefined,
      to: to || undefined,
    }),
    [status, cropCycleId, projectId, from, to],
  );

  const { data: jobs, isLoading } = useFieldJobs(filters);
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();
  const { hasRole } = useRole();

  const sortedJobs = useMemo(() => {
    const list = [...(jobs ?? [])];
    list.sort((a, b) => {
      const da = a.status === 'DRAFT' ? 0 : 1;
      const db = b.status === 'DRAFT' ? 0 : 1;
      if (da !== db) return da - db;
      return String(b.job_date ?? '').localeCompare(String(a.job_date ?? ''));
    });
    return list;
  }, [jobs]);

  const columns: Column<FieldJob>[] = useMemo(
    () => [
      {
        header: 'Job date',
        accessor: (r) => (
          <span className="tabular-nums">{formatDate(r.job_date, { variant: 'medium' })}</span>
        ),
      },
      {
        header: 'Reference',
        accessor: (r) => (
          <span className="font-medium text-gray-900">{r.doc_no?.trim() || `— ${r.id.slice(0, 8)}`}</span>
        ),
      },
      {
        header: 'Project / cycle',
        accessor: (r) => (
          <span className="text-gray-700">
            {[r.project?.name, r.crop_cycle?.name].filter(Boolean).join(' · ') || '—'}
          </span>
        ),
      },
      {
        header: 'Status',
        accessor: (r) => <PostingStatusBadge status={r.status} />,
      },
      {
        header: 'Actions',
        accessor: (r) => (
          <div className="flex flex-wrap gap-2" onClick={(e) => e.stopPropagation()}>
            {r.status === 'DRAFT' && hasRole(['tenant_admin', 'accountant', 'operator']) ? (
              <button
                type="button"
                className="text-sm font-medium text-[#1F6F5C] hover:underline"
                onClick={() => navigate(`/app/crop-ops/field-jobs/${r.id}`, { state: { from: location.pathname } })}
              >
                Continue editing
              </button>
            ) : null}
            <button
              type="button"
              className="text-sm font-medium text-gray-700 hover:underline"
              onClick={() => navigate(`/app/crop-ops/field-jobs/${r.id}`, { state: { from: location.pathname } })}
            >
              View
            </button>
          </div>
        ),
      },
    ],
    [formatDate, hasRole, navigate, location.pathname],
  );

  const clearFilters = () => {
    setStatus('');
    setCropCycleId('');
    setProjectId('');
    setFrom('');
    setTo('');
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title="Field jobs"
        description="The primary place to record field work: labour, machinery, and inputs together in one document."
        helper="Avoid duplicating the same work in separate machine usage or labour logs—capture it here, then post when ready for accounting."
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Field jobs' },
        ]}
        right={
          <Link
            to="/app/crop-ops/field-jobs/new"
            className="inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New field job
          </Link>
        }
      />

      <PrimaryWorkflowBanner variant="field-job" />

      <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label className="block text-xs font-medium text-gray-500">Status</label>
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
            >
              <option value="">All</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
              <option value="REVERSED">Reversed</option>
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500">Crop cycle</label>
            <select
              value={cropCycleId}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setProjectId('');
              }}
              className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm min-w-[180px]"
            >
              <option value="">All</option>
              {(cropCycles ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500">Project</label>
            <select
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm min-w-[200px]"
            >
              <option value="">All</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
            />
          </div>
          <div>
            <label className="block text-xs font-medium text-gray-500">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
            />
          </div>
          <button
            type="button"
            onClick={clearFilters}
            className="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : (
        <DataTable<FieldJob>
          columns={columns}
          data={sortedJobs}
          emptyMessage="No field jobs yet. Create one to get started."
          onRowClick={(r) => navigate(`/app/crop-ops/field-jobs/${r.id}`, { state: { from: location.pathname } })}
        />
      )}
    </div>
  );
}
