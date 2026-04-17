import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  useMaintenanceJobsQuery,
  useDeleteMaintenanceJob,
  usePostMaintenanceJob,
  useReverseMaintenanceJob,
} from '../../hooks/useMachinery';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useParties } from '../../hooks/useParties';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { PageHeader } from '../../components/PageHeader';
import type { MachineMaintenanceJob } from '../../types';
import { Badge } from '../../components/Badge';
import { v4 as uuidv4 } from 'uuid';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

export default function MaintenanceJobsPage() {
  const { formatDate } = useFormatting();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    machine_id: searchParams.get('machine_id') || '',
    from: searchParams.get('from') || '',
    to: searchParams.get('to') || '',
    vendor_party_id: searchParams.get('vendor_party_id') || '',
  });

  const jobFilters = {
    ...filters,
    status: (filters.status === 'DRAFT' || filters.status === 'POSTED' || filters.status === 'REVERSED'
      ? filters.status
      : undefined) as 'DRAFT' | 'POSTED' | 'REVERSED' | undefined,
  };
  const { data: jobs, isLoading } = useMaintenanceJobsQuery(jobFilters);
  const { data: machines } = useMachinesQuery();
  const { data: parties } = useParties();
  const { hasRole } = useRole();
  const deleteMutation = useDeleteMaintenanceJob();
  const postMutation = usePostMaintenanceJob();
  const reverseMutation = useReverseMaintenanceJob();

  const canCreate = hasRole(['tenant_admin', 'accountant', 'operator']);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const [postingJobId, setPostingJobId] = useState<string | null>(null);
  const [reversingJobId, setReversingJobId] = useState<string | null>(null);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const handlePost = async () => {
    if (!postingJobId || !postingDate) return;
    try {
      await postMutation.mutateAsync({
        id: postingJobId,
        payload: { posting_date: postingDate, idempotency_key: uuidv4() },
      });
      setPostingJobId(null);
      postMutation.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  const handleReverse = async () => {
    if (!reversingJobId || !reverseDate) return;
    try {
      await reverseMutation.mutateAsync({
        id: reversingJobId,
        payload: { posting_date: reverseDate, reason: reverseReason || undefined },
      });
      setReversingJobId(null);
      setReverseReason('');
      reverseMutation.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  const handleFilterChange = (key: string, value: string) => {
    const newFilters = { ...filters, [key]: value };
    setFilters(newFilters);
    const params = new URLSearchParams();
    Object.entries(newFilters).forEach(([k, v]) => {
      if (v) params.set(k, v);
    });
    setSearchParams(params);
  };

  const clearFilters = () => {
    setFilters({ status: '', machine_id: '', from: '', to: '', vendor_party_id: '' });
    setSearchParams(new URLSearchParams());
  };

  const hasFilters = !!(
    filters.status ||
    filters.machine_id ||
    filters.from ||
    filters.to ||
    filters.vendor_party_id
  );

  const jobList = (jobs ?? []) as MachineMaintenanceJob[];

  const sortedJobList = useMemo(() => {
    const draftFirst = (a: MachineMaintenanceJob, b: MachineMaintenanceJob) => {
      const da = a.status === 'DRAFT' ? 0 : 1;
      const db = b.status === 'DRAFT' ? 0 : 1;
      if (da !== db) return da - db;
      const dateA = a.job_date ? String(a.job_date).slice(0, 10) : '';
      const dateB = b.job_date ? String(b.job_date).slice(0, 10) : '';
      return dateB.localeCompare(dateA);
    };
    return [...jobList].sort(draftFirst);
  }, [jobList]);

  const summaryLine = useMemo(() => {
    const n = jobList.length;
    const label = n === 1 ? 'maintenance job' : 'maintenance jobs';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (n === 0) return base;
    const draftCount = jobList.filter((j) => j.status === 'DRAFT').length;
    return draftCount ? `${base} · ${draftCount} draft` : base;
  }, [jobList, hasFilters]);

  const handleDelete = async (id: string, e: React.MouseEvent) => {
    e.stopPropagation();
    if (window.confirm('Are you sure you want to delete this maintenance job?')) {
      try {
        await deleteMutation.mutateAsync(id);
      } catch (error) {
        // Error handled by mutation
      }
    }
  };

  const canConfirmListPost = Boolean(postingDate && postingJobId);
  const canConfirmListReverse = Boolean(reverseDate && reversingJobId);

  const columns: Column<MachineMaintenanceJob>[] = [
    {
      header: 'Date',
      accessor: (row) => (
        <span className="tabular-nums text-gray-900">{formatDate(row.job_date, { variant: 'medium' })}</span>
      ),
    },
    {
      header: 'Machine',
      accessor: (row) => row.machine?.name || row.machine?.code || '—',
    },
    {
      header: 'Maintenance type',
      accessor: (row) => row.maintenance_type?.name || '—',
    },
    {
      header: 'Note',
      accessor: (row) =>
        row.notes ? (
          <span className="block max-w-[24rem] truncate" title={row.notes}>
            {row.notes}
          </span>
        ) : (
          '—'
        ),
    },
    {
      header: 'Status',
      accessor: (row) => (
        <Badge variant={row.status === 'DRAFT' ? 'warning' : row.status === 'POSTED' ? 'success' : 'neutral'}>
          {row.status === 'DRAFT' ? 'Draft' : row.status === 'POSTED' ? 'Posted' : 'Reversed'}
        </Badge>
      ),
    },
    { header: 'Reference', accessor: (row) => <span className="tabular-nums">{row.job_no}</span> },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              navigate(`/app/machinery/maintenance-jobs/${row.id}`);
            }}
            className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </button>
          {row.status === 'DRAFT' && canCreate && (
            <>
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  navigate(`/app/machinery/maintenance-jobs/${row.id}/edit`);
                }}
                className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                Continue editing
              </button>
              {canPost && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    postMutation.reset();
                    setPostingJobId(row.id);
                  }}
                  className="text-green-600 hover:text-green-800 font-medium"
                >
                  Record to accounts
                </button>
              )}
              <button
                type="button"
                onClick={(e) => handleDelete(row.id, e)}
                className="text-red-600 hover:text-red-800"
                disabled={deleteMutation.isPending}
              >
                Delete
              </button>
            </>
          )}
          {row.status === 'POSTED' && canPost && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                reverseMutation.reset();
                setReversingJobId(row.id);
              }}
              className="text-red-600 hover:text-red-800 font-medium"
            >
              Reverse
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Maintenance Jobs"
        tooltip="Track maintenance work and repairs for your machines."
        description="Track maintenance work and repairs for your machines."
        helper="Use maintenance jobs to record servicing, repairs, and vendor work against machines."
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Maintenance Jobs' },
        ]}
        right={
          canCreate ? (
            <button
              type="button"
              onClick={() => navigate('/app/machinery/maintenance-jobs/new')}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              New maintenance job
            </button>
          ) : undefined
        }
      />

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
        <div className="flex flex-wrap gap-4 items-end">
            <div className="flex flex-col gap-1 min-w-[10rem]">
              <label className="text-sm font-medium text-gray-700">Status</label>
              <select
                value={filters.status}
                onChange={(e) => handleFilterChange('status', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              >
                <option value="">All</option>
                <option value="DRAFT">Draft</option>
                <option value="POSTED">Posted</option>
                <option value="REVERSED">Reversed</option>
              </select>
            </div>
            <div className="flex flex-col gap-1 min-w-[12rem]">
              <label className="text-sm font-medium text-gray-700">Machine</label>
              <select
                value={filters.machine_id}
                onChange={(e) => handleFilterChange('machine_id', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              >
                <option value="">All</option>
                {machines?.map((machine) => (
                  <option key={machine.id} value={machine.id}>
                    {machine.code} - {machine.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1 min-w-[12rem]">
              <label className="text-sm font-medium text-gray-700">Vendor</label>
              <select
                value={filters.vendor_party_id}
                onChange={(e) => handleFilterChange('vendor_party_id', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              >
                <option value="">All</option>
                {parties?.filter((p) => p.party_types?.includes('VENDOR')).map((party) => (
                  <option key={party.id} value={party.id}>
                    {party.name}
                  </option>
                ))}
              </select>
            </div>
            <div className="flex flex-col gap-1 min-w-[10rem]">
              <label className="text-sm font-medium text-gray-700">From</label>
              <input
                type="date"
                value={filters.from}
                onChange={(e) => handleFilterChange('from', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </div>
            <div className="flex flex-col gap-1 min-w-[10rem]">
              <label className="text-sm font-medium text-gray-700">To</label>
              <input
                type="date"
                value={filters.to}
                onChange={(e) => handleFilterChange('to', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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
      ) : jobList.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No maintenance jobs yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Create a job to track repairs, servicing, or vendor maintenance against your machines.
          </p>
        </div>
      ) : jobList.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No maintenance jobs match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting filters or clear them to see all jobs.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
          <DataTable
            data={sortedJobList}
            columns={columns}
            onRowClick={(row) => navigate(`/app/machinery/maintenance-jobs/${row.id}`)}
            emptyMessage=""
          />
        </div>
      )}

      {/* Post Modal */}
      {postingJobId && (
        <Modal
          isOpen={!!postingJobId}
          title="Record maintenance job to accounts"
          onClose={() => {
            setPostingJobId(null);
            postMutation.reset();
          }}
        >
          <div className="space-y-4">
            <p className="text-sm text-gray-700 leading-relaxed">
              This will record maintenance costs for this job in the accounts for the posting date below.
            </p>
            <PrePostChecklist
              items={[{ ok: Boolean(postingDate), label: 'Posting date chosen' }]}
              blockingHint={!postingDate ? 'Choose a posting date before recording.' : undefined}
            />
            <OperatorErrorCallout error={postMutation.isError ? formatOperatorError(postMutation.error) : null} />
            <FormField label="Posting date" required>
              <input
                type="date"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] min-h-[44px]"
                required
              />
            </FormField>
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
              <button
                type="button"
                onClick={() => {
                  setPostingJobId(null);
                  postMutation.reset();
                }}
                className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handlePost}
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50 min-h-[44px]"
                disabled={postMutation.isPending || !canConfirmListPost}
              >
                {postMutation.isPending ? 'Recording…' : 'Confirm'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* Reverse Modal */}
      {reversingJobId && (
        <Modal
          isOpen={!!reversingJobId}
          title="Reverse maintenance job"
          onClose={() => {
            setReversingJobId(null);
            setReverseReason('');
            reverseMutation.reset();
          }}
        >
          <div className="space-y-4">
            <p className="text-sm text-gray-700 leading-relaxed">
              This creates offsetting entries as of the posting date below. Cancel if you are not ready.
            </p>
            <PrePostChecklist
              items={[{ ok: Boolean(reverseDate), label: 'Posting date chosen' }]}
              blockingHint={!reverseDate ? 'Choose a posting date before reversing.' : undefined}
            />
            <OperatorErrorCallout error={reverseMutation.isError ? formatOperatorError(reverseMutation.error) : null} />
            <FormField label="Posting date" required>
              <input
                type="date"
                value={reverseDate}
                onChange={(e) => setReverseDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] min-h-[44px]"
                required
              />
            </FormField>
            <FormField label="Reason (optional)">
              <textarea
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                rows={3}
                maxLength={500}
                placeholder="Optional reason for reversal"
              />
            </FormField>
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
              <button
                type="button"
                onClick={() => {
                  setReversingJobId(null);
                  setReverseReason('');
                  reverseMutation.reset();
                }}
                className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
                disabled={reverseMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleReverse}
                className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 disabled:opacity-50 min-h-[44px]"
                disabled={reverseMutation.isPending || !canConfirmListReverse}
              >
                {reverseMutation.isPending ? 'Reversing…' : 'Confirm reverse'}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
