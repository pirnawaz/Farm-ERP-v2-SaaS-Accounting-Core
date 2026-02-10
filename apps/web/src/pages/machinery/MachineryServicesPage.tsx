import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  useMachineryServicesQuery,
  usePostMachineryService,
  useReverseMachineryService,
} from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { PageHeader } from '../../components/PageHeader';
import type { MachineryService } from '../../types';

export default function MachineryServicesPage() {
  const { formatMoney, formatDate } = useFormatting();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    project_id: searchParams.get('project_id') || '',
  });
  const serviceFilters = {
    ...filters,
    status: (filters.status === 'DRAFT' || filters.status === 'POSTED' || filters.status === 'REVERSED'
      ? filters.status
      : undefined) as 'DRAFT' | 'POSTED' | 'REVERSED' | undefined,
    project_id: filters.project_id || undefined,
  };
  const { data: services, isLoading } = useMachineryServicesQuery(serviceFilters);
  const { data: projects } = useProjects();
  const { hasRole } = useRole();
  const postMutation = usePostMachineryService();
  const reverseMutation = useReverseMachineryService();

  const canCreate = hasRole(['tenant_admin', 'accountant', 'operator']);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const [postingId, setPostingId] = useState<string | null>(null);
  const [reversingId, setReversingId] = useState<string | null>(null);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const handlePost = async () => {
    if (!postingId) return;
    try {
      await postMutation.mutateAsync({
        id: postingId,
        payload: { posting_date: postingDate },
      });
      setPostingId(null);
    } catch {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!reversingId) return;
    try {
      await reverseMutation.mutateAsync({
        id: reversingId,
        payload: { posting_date: reverseDate, reason: reverseReason || undefined },
      });
      setReversingId(null);
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

  const columns: Column<MachineryService>[] = [
    {
      header: 'Date',
      accessor: (row) =>
        row.posting_date ? formatDate(row.posting_date) : (row.created_at ? formatDate(row.created_at) : '—'),
    },
    {
      header: 'Project',
      accessor: (row) => row.project?.name ?? row.project_id ?? '—',
    },
    {
      header: 'Machine',
      accessor: (row) => row.machine?.code ?? row.machine?.name ?? '—',
    },
    { header: 'Scope', accessor: 'allocation_scope' },
    {
      header: 'Qty',
      accessor: (row) => (row.quantity != null ? String(row.quantity) : '—'),
    },
    {
      header: 'Amount',
      accessor: (row) => (
        <span className="tabular-nums">{row.amount != null ? formatMoney(row.amount) : '—'}</span>
      ),
    },
    {
      header: 'Status',
      accessor: (row) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            row.status === 'DRAFT'
              ? 'bg-yellow-100 text-yellow-800'
              : row.status === 'POSTED'
                ? 'bg-green-100 text-green-800'
                : 'bg-red-100 text-red-800'
          }`}
        >
          {row.status}
        </span>
      ),
    },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex gap-2">
          <button
            onClick={(e) => {
              e.stopPropagation();
              navigate(`/app/machinery/services/${row.id}`);
            }}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </button>
          {row.status === 'DRAFT' && canCreate && (
            <>
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  navigate(`/app/machinery/services/${row.id}/edit`);
                }}
                className="text-blue-600 hover:text-blue-800"
              >
                Edit
              </button>
              {canPost && (
                <button
                  onClick={(e) => {
                    e.stopPropagation();
                    setPostingId(row.id);
                  }}
                  className="text-green-600 hover:text-green-800"
                >
                  Post
                </button>
              )}
            </>
          )}
          {row.status === 'POSTED' && canPost && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                setReversingId(row.id);
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

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Machinery Services"
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Services' },
        ]}
        right={
          canCreate ? (
            <button
              onClick={() => navigate('/app/machinery/services/new')}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              New Service
            </button>
          ) : undefined
        }
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
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
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
            <select
              value={filters.project_id}
              onChange={(e) => handleFilterChange('project_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={(services ?? []) as MachineryService[]}
          columns={columns}
          onRowClick={(row) => navigate(`/app/machinery/services/${row.id}`)}
        />
      </div>

      {postingId && (
        <Modal isOpen={!!postingId} title="Post Service" onClose={() => setPostingId(null)}>
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
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setPostingId(null)}
                className="px-4 py-2 border rounded"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                disabled={postMutation.isPending}
              >
                {postMutation.isPending ? 'Posting...' : 'Post'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {reversingId && (
        <Modal
          isOpen={!!reversingId}
          title="Reverse Service"
          onClose={() => {
            setReversingId(null);
            setReverseReason('');
          }}
        >
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
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => {
                  setReversingId(null);
                  setReverseReason('');
                }}
                className="px-4 py-2 border rounded"
                disabled={reverseMutation.isPending}
              >
                Cancel
              </button>
              <button
                onClick={handleReverse}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
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
