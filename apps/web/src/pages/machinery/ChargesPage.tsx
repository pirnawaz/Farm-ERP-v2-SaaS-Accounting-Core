import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useChargesQuery, useGenerateCharges } from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useParties } from '../../hooks/useParties';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import type { MachineryCharge } from '../../types';
import { Badge } from '../../components/Badge';

export default function ChargesPage() {
  const { formatMoney, formatDate } = useFormatting();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    project_id: searchParams.get('project_id') || '',
    crop_cycle_id: searchParams.get('crop_cycle_id') || '',
    from: searchParams.get('from') || '',
    to: searchParams.get('to') || '',
    landlord_party_id: searchParams.get('landlord_party_id') || '',
  });
  const [showGenerateModal, setShowGenerateModal] = useState(false);

  const chargeFilters = {
    ...filters,
    status: (filters.status === 'DRAFT' || filters.status === 'POSTED' || filters.status === 'REVERSED'
      ? filters.status
      : undefined) as 'DRAFT' | 'POSTED' | 'REVERSED' | undefined,
  };
  const { data: charges, isLoading } = useChargesQuery(chargeFilters as import('../../api/machinery').ChargeFilters);
  const { data: projects } = useProjects();
  const { data: cropCycles } = useCropCycles();
  const { data: parties } = useParties();
  const { hasRole } = useRole();
  const generateMutation = useGenerateCharges();

  const canGenerate = hasRole(['tenant_admin', 'accountant']);

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
    const cleared = {
      status: '',
      project_id: '',
      crop_cycle_id: '',
      from: '',
      to: '',
      landlord_party_id: '',
    };
    setFilters(cleared);
    setSearchParams(new URLSearchParams());
  };

  const hasFilters = !!(
    filters.status ||
    filters.project_id ||
    filters.crop_cycle_id ||
    filters.from ||
    filters.to ||
    filters.landlord_party_id
  );

  const chargeList = (charges ?? []) as MachineryCharge[];

  const summaryLine = useMemo(() => {
    const n = chargeList.length;
    const label = n === 1 ? 'machinery charge' : 'machinery charges';
    return hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
  }, [chargeList.length, hasFilters]);

  const handleGenerate = async (payload: any) => {
    try {
      const result = await generateMutation.mutateAsync(payload);
      setShowGenerateModal(false);
      
      // If array (mixed scopes), navigate to first charge
      if (Array.isArray(result)) {
        if (result.length > 0) {
          navigate(`/app/machinery/charges/${result[0].id}`);
        }
      } else {
        navigate(`/app/machinery/charges/${result.id}`);
      }
    } catch (error) {
      // Error handled by mutation
    }
  };

  const columns: Column<MachineryCharge>[] = [
    {
      header: 'Date',
      accessor: (row) => (
        <span className="tabular-nums text-gray-900">{formatDate(row.charge_date, { variant: 'medium' })}</span>
      ),
    },
    {
      header: 'Field cycle',
      accessor: (row) => row.project?.name || '—',
    },
    {
      header: 'Crop cycle',
      accessor: (row) => row.crop_cycle?.name || '—',
    },
    {
      header: 'Beneficiary',
      accessor: (row) => (row.pool_scope === 'LANDLORD_ONLY' ? 'My farm' : row.pool_scope === 'HARI_ONLY' ? 'Hari only' : row.pool_scope === 'SHARED' ? 'Shared' : row.pool_scope ?? '—'),
    },
    {
      header: 'Amount',
      accessor: (row) => <span className="tabular-nums text-gray-900">{formatMoney(row.total_amount)}</span>,
    },
    {
      header: 'Status',
      accessor: (row) => (
        <Badge variant={row.status === 'DRAFT' ? 'warning' : row.status === 'POSTED' ? 'success' : 'neutral'}>
          {row.status === 'DRAFT' ? 'Draft' : row.status === 'POSTED' ? 'Posted' : 'Reversed'}
        </Badge>
      ),
    },
    { header: 'Reference', accessor: (row) => <span className="tabular-nums">{row.charge_no || '—'}</span> },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex gap-2">
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              navigate(`/app/machinery/charges/${row.id}`);
            }}
            className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Machinery Charges"
        tooltip="Track costs associated with machine usage and operations."
        description="Track costs associated with machine usage and operations."
        helper="Charges allocate machinery usage to field and crop cycles; use this list to review amounts and status."
        backTo="/app/machinery"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Machinery Charges' },
        ]}
        right={
          canGenerate ? (
            <button
              type="button"
              onClick={() => setShowGenerateModal(true)}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
            >
              Generate charges
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
              {projects?.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Crop cycle</label>
            <select
              value={filters.crop_cycle_id}
              onChange={(e) => handleFilterChange('crop_cycle_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {cropCycles?.map((cycle) => (
                <option key={cycle.id} value={cycle.id}>
                  {cycle.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Landlord</label>
            <select
              value={filters.landlord_party_id}
              onChange={(e) => handleFilterChange('landlord_party_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {parties
                ?.filter((p) => p.party_types?.includes('LANDLORD'))
                .map((party) => (
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
      ) : chargeList.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No machinery charges yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Generate charges from machine usage to record machinery-related costs against cycles.
          </p>
        </div>
      ) : chargeList.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No machinery charges match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting filters or clear them to see all charges.</p>
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
            data={chargeList}
            columns={columns}
            onRowClick={(row) => navigate(`/app/machinery/charges/${row.id}`)}
            emptyMessage=""
          />
        </div>
      )}

      {showGenerateModal && (
        <GenerateChargesModal
          onClose={() => setShowGenerateModal(false)}
          onGenerate={handleGenerate}
          isLoading={generateMutation.isPending}
        />
      )}
    </div>
  );
}

function GenerateChargesModal({
  onClose,
  onGenerate,
  isLoading,
}: {
  onClose: () => void;
  onGenerate: (payload: any) => void;
  isLoading: boolean;
}) {
  const { data: projects } = useProjects();
  const [formData, setFormData] = useState({
    project_id: '',
    from: '',
    to: '',
    pool_scope: '' as 'LANDLORD_ONLY' | 'SHARED' | 'HARI_ONLY' | '',
    charge_date: new Date().toISOString().split('T')[0],
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: any = {
      project_id: formData.project_id,
      from: formData.from,
      to: formData.to,
    };
    if (formData.pool_scope) {
      payload.pool_scope = formData.pool_scope;
    }
    if (formData.charge_date) {
      payload.charge_date = formData.charge_date;
    }
    onGenerate(payload);
  };

  return (
    <Modal isOpen={true} title="Generate Charges" onClose={onClose}>
      <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <FormField label="Project" required className="md:col-span-2">
          <select
            value={formData.project_id}
            onChange={(e) => setFormData({ ...formData, project_id: e.target.value })}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            required
          >
            <option value="">Select Project</option>
            {projects?.map((project) => (
              <option key={project.id} value={project.id}>
                {project.name}
              </option>
            ))}
          </select>
        </FormField>

        <FormField label="From Date" required>
          <input
            type="date"
            value={formData.from}
            onChange={(e) => setFormData({ ...formData, from: e.target.value })}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            required
          />
        </FormField>

        <FormField label="To Date" required>
          <input
            type="date"
            value={formData.to}
            onChange={(e) => setFormData({ ...formData, to: e.target.value })}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            required
          />
        </FormField>

        <FormField label="Beneficiary">
          <select
            value={formData.pool_scope}
            onChange={(e) =>
              setFormData({ ...formData, pool_scope: e.target.value as 'LANDLORD_ONLY' | 'SHARED' | 'HARI_ONLY' | '' })
            }
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            <option value="">All (create separate charges if mixed)</option>
            <option value="LANDLORD_ONLY">My farm</option>
            <option value="SHARED">Shared</option>
            <option value="HARI_ONLY">Hari only</option>
          </select>
        </FormField>

        <FormField label="Charge Date">
          <input
            type="date"
            value={formData.charge_date}
            onChange={(e) => setFormData({ ...formData, charge_date: e.target.value })}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          />
        </FormField>

        <div className="md:col-span-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-2">
          <button
            type="button"
            onClick={onClose}
            className="w-full sm:w-auto px-4 py-2 border rounded"
            disabled={isLoading}
          >
            Cancel
          </button>
          <button
            type="submit"
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a]"
            disabled={isLoading || !formData.project_id || !formData.from || !formData.to}
          >
            {isLoading ? 'Generating...' : 'Generate'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
