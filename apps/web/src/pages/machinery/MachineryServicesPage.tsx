import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  useMachineryServicesQuery,
  usePostMachineryService,
  useReverseMachineryService,
  useMachinesQuery,
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
import { Badge } from '../../components/Badge';

export default function MachineryServicesPage() {
  const { formatMoney, formatDate } = useFormatting();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    project_id: searchParams.get('project_id') || '',
    machine_id: searchParams.get('machine_id') || '',
    from: searchParams.get('from') || '',
    to: searchParams.get('to') || '',
  });
  const serviceFilters = {
    ...filters,
    status: (filters.status === 'DRAFT' || filters.status === 'POSTED' || filters.status === 'REVERSED'
      ? filters.status
      : undefined) as 'DRAFT' | 'POSTED' | 'REVERSED' | undefined,
    project_id: filters.project_id || undefined,
    machine_id: filters.machine_id || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
  };
  const { data: services, isLoading } = useMachineryServicesQuery(serviceFilters);
  const { data: projects } = useProjects();
  const { data: machines } = useMachinesQuery();
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

  const clearFilters = () => {
    const cleared = { status: '', project_id: '', machine_id: '', from: '', to: '' };
    setFilters(cleared);
    setSearchParams(new URLSearchParams());
  };

  const hasFilters = !!(
    filters.status ||
    filters.project_id ||
    filters.machine_id ||
    filters.from ||
    filters.to
  );

  const serviceList = (services ?? []) as MachineryService[];

  const summaryLine = useMemo(() => {
    const n = serviceList.length;
    const label = n === 1 ? 'service record' : 'service records';
    return hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
  }, [serviceList.length, hasFilters]);

  const columns: Column<MachineryService>[] = [
    {
      header: 'Date',
      accessor: (row) => {
        const d = row.posting_date || row.created_at;
        return d ? (
          <span className="tabular-nums text-gray-900">{formatDate(d, { variant: 'medium' })}</span>
        ) : (
          '—'
        );
      },
    },
    {
      header: 'Field cycle',
      accessor: (row) => row.project?.name ?? row.project_id ?? '—',
    },
    {
      header: 'Machine',
      accessor: (row) => row.machine?.code ?? row.machine?.name ?? '—',
    },
    {
      header: 'Beneficiary',
      accessor: (row) =>
        row.allocation_scope === 'LANDLORD_ONLY'
          ? 'My farm'
          : row.allocation_scope === 'HARI_ONLY'
            ? 'Hari only'
            : row.allocation_scope === 'SHARED'
              ? 'Shared'
              : (row.allocation_scope ?? '—'),
    },
    {
      header: 'Quantity',
      accessor: (row) => <span className="tabular-nums">{row.quantity != null ? String(row.quantity) : '—'}</span>,
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
        <Badge variant={row.status === 'DRAFT' ? 'warning' : row.status === 'POSTED' ? 'success' : 'neutral'}>
          {row.status === 'DRAFT' ? 'Draft' : row.status === 'POSTED' ? 'Posted' : 'Reversed'}
        </Badge>
      ),
    },
    { header: 'Reference', accessor: (row) => <span className="tabular-nums">{row.id.slice(0, 8)}…</span> },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              navigate(`/app/machinery/services/${row.id}`);
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
                  navigate(`/app/machinery/services/${row.id}/edit`);
                }}
                className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
              >
                Edit
              </button>
              {canPost && (
                <button
                  type="button"
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
              type="button"
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

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Service History"
        tooltip="View past service records for your machines."
        description="View past service records for your machines."
        helper="Service records capture machine servicing with field cycle context, quantities, and allocation where relevant."
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Service History' },
        ]}
        right={
          canCreate ? (
            <button
              type="button"
              onClick={() => navigate('/app/machinery/services/new')}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              New service
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
            <label className="text-sm font-medium text-gray-700">Field cycle</label>
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
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Machine</label>
            <select
              value={filters.machine_id}
              onChange={(e) => handleFilterChange('machine_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.code} – {m.name}
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
      ) : serviceList.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No service records yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Add a service record when you log machine servicing so you can review history here.
          </p>
        </div>
      ) : serviceList.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No service records match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting filters or clear them to see all records.</p>
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
            data={serviceList}
            columns={columns}
            onRowClick={(row) => navigate(`/app/machinery/services/${row.id}`)}
            emptyMessage=""
          />
        </div>
      )}

      {postingId && (
        <Modal isOpen={!!postingId} title="Post Service" onClose={() => setPostingId(null)}>
          <div className="space-y-4">
            {(() => {
              const msg = (postMutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message;
              return msg ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{msg}</div> : null;
            })()}
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
                onClick={() => setPostingId(null)}
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
            {(() => {
              const msg = (reverseMutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message;
              return msg ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{msg}</div> : null;
            })()}
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
                onClick={() => {
                  setReversingId(null);
                  setReverseReason('');
                }}
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
