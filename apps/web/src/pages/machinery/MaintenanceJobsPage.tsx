import { useState } from 'react';
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

export default function MaintenanceJobsPage() {
  const { formatMoney, formatDate } = useFormatting();
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
    if (!postingJobId) return;
    try {
      await postMutation.mutateAsync({
        id: postingJobId,
        payload: { posting_date: postingDate },
      });
      setPostingJobId(null);
    } catch {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!reversingJobId) return;
    try {
      await reverseMutation.mutateAsync({
        id: reversingJobId,
        payload: { posting_date: reverseDate, reason: reverseReason || undefined },
      });
      setReversingJobId(null);
      setReverseReason('');
    } catch {
      // Error handled by mutation
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

  const columns: Column<MachineMaintenanceJob>[] = [
    { header: 'Job No', accessor: 'job_no' },
    {
      header: 'Machine',
      accessor: (row) => row.machine?.code || 'N/A',
    },
    { header: 'Job Date', accessor: (row) => formatDate(row.job_date) },
    {
      header: 'Vendor',
      accessor: (row) => row.vendor_party?.name || '—',
    },
    {
      header: 'Total Amount',
      accessor: (row) => <span className="tabular-nums">{formatMoney(row.total_amount)}</span>,
    },
    { header: 'Status', accessor: 'status' },
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
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
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
                className="text-blue-600 hover:text-blue-800"
              >
                Edit
              </button>
              {canPost && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    setPostingJobId(row.id);
                  }}
                  className="text-green-600 hover:text-green-800"
                >
                  Post
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
                setReversingJobId(row.id);
              }}
              className="text-red-600 hover:text-red-800"
            >
              Reverse
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Maintenance Jobs"
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Maintenance' },
        ]}
        right={
          canCreate ? (
            <button
              type="button"
              onClick={() => navigate('/app/machinery/maintenance-jobs/new')}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              New Maintenance Job
            </button>
          ) : undefined
        }
      />

      <div className="space-y-4">
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
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
              <label className="text-sm font-medium text-gray-700">Vendor Party</label>
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
              <label className="text-sm font-medium text-gray-700">Date From</label>
              <input
                type="date"
                value={filters.from}
                onChange={(e) => handleFilterChange('from', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </div>
            <div className="flex flex-col gap-1 min-w-[10rem]">
              <label className="text-sm font-medium text-gray-700">Date To</label>
              <input
                type="date"
                value={filters.to}
                onChange={(e) => handleFilterChange('to', e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-lg shadow overflow-x-auto">
          {isLoading ? (
            <div className="flex justify-center py-12">
              <LoadingSpinner size="lg" />
            </div>
          ) : (
            <DataTable
              data={(jobs ?? []) as MachineMaintenanceJob[]}
              columns={columns}
              onRowClick={(row) => navigate(`/app/machinery/maintenance-jobs/${row.id}`)}
            />
          )}
        </div>
      </div>

      {/* Post Modal */}
      {postingJobId && (
        <Modal isOpen={!!postingJobId} title="Post Maintenance Job" onClose={() => setPostingJobId(null)}>
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                required
              />
            </FormField>
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
              <button
                type="button"
                onClick={() => setPostingJobId(null)}
                className="w-full sm:w-auto px-4 py-2 border rounded"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handlePost}
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                disabled={postMutation.isPending}
              >
                {postMutation.isPending ? 'Posting...' : 'Post'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* Reverse Modal */}
      {reversingJobId && (
        <Modal isOpen={!!reversingJobId} title="Reverse Maintenance Job" onClose={() => { setReversingJobId(null); setReverseReason(''); }}>
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={reverseDate}
                onChange={(e) => setReverseDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                required
              />
            </FormField>
            <FormField label="Reason">
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
                onClick={() => { setReversingJobId(null); setReverseReason(''); }}
                className="w-full sm:w-auto px-4 py-2 border rounded"
                disabled={reverseMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleReverse}
                className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
                disabled={reverseMutation.isPending}
              >
                {reverseMutation.isPending ? 'Reversing...' : 'Reverse'}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
