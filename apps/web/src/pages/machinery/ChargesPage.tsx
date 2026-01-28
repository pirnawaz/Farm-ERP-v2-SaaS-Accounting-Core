import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useChargesQuery, useGenerateCharges } from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useParties } from '../../hooks/useParties';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import type { MachineryCharge } from '../../types';

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

  const { data: charges, isLoading } = useChargesQuery(filters);
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
    { header: 'Charge No', accessor: 'charge_no' },
    {
      header: 'Project',
      accessor: (row) => row.project?.name || 'N/A',
    },
    {
      header: 'Crop Cycle',
      accessor: (row) => row.crop_cycle?.name || 'N/A',
    },
    { header: 'Pool Scope', accessor: 'pool_scope' },
    { header: 'Charge Date', accessor: (row) => formatDate(row.charge_date) },
    {
      header: 'Total Amount',
      accessor: (row) => <span className="tabular-nums">{formatMoney(row.total_amount)}</span>,
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <div className="flex gap-2">
          <button
            onClick={(e) => {
              e.stopPropagation();
              navigate(`/app/machinery/charges/${row.id}`);
            }}
            className="text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </button>
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
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Machinery Charges</h1>
        {canGenerate && (
          <button
            onClick={() => setShowGenerateModal(true)}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            Generate Charges
          </button>
        )}
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
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
              {projects?.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Crop Cycle</label>
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
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Landlord Party</label>
            <select
              value={filters.landlord_party_id}
              onChange={(e) => handleFilterChange('landlord_party_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {parties?.filter((p) => p.party_types?.includes('LANDLORD')).map((party) => (
                <option key={party.id} value={party.id}>
                  {party.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Date From</label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => handleFilterChange('from', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Date To</label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => handleFilterChange('to', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={charges || []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/machinery/charges/${row.id}`)}
        />
      </div>

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
  const { data: parties } = useParties();
  const [formData, setFormData] = useState({
    project_id: '',
    landlord_party_id: '',
    from: '',
    to: '',
    pool_scope: '' as 'SHARED' | 'HARI_ONLY' | '',
    charge_date: new Date().toISOString().split('T')[0],
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: any = {
      project_id: formData.project_id,
      landlord_party_id: formData.landlord_party_id,
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
      <form onSubmit={handleSubmit} className="space-y-4">
        <FormField label="Project" required>
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

        <FormField label="Landlord Party" required>
          <select
            value={formData.landlord_party_id}
            onChange={(e) => setFormData({ ...formData, landlord_party_id: e.target.value })}
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            required
          >
            <option value="">Select Landlord Party</option>
            {parties?.filter((p) => p.party_types?.includes('LANDLORD')).map((party) => (
              <option key={party.id} value={party.id}>
                {party.name}
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

        <FormField label="Pool Scope">
          <select
            value={formData.pool_scope}
            onChange={(e) =>
              setFormData({ ...formData, pool_scope: e.target.value as 'SHARED' | 'HARI_ONLY' | '' })
            }
            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          >
            <option value="">All (create separate charges if mixed)</option>
            <option value="SHARED">SHARED</option>
            <option value="HARI_ONLY">HARI_ONLY</option>
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

        <div className="flex justify-end gap-2 mt-6">
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 border rounded"
            disabled={isLoading}
          >
            Cancel
          </button>
          <button
            type="submit"
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a]"
            disabled={isLoading || !formData.project_id || !formData.landlord_party_id || !formData.from || !formData.to}
          >
            {isLoading ? 'Generating...' : 'Generate'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
