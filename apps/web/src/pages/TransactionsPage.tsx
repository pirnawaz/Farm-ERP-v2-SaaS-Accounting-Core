import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { useCropCycles } from '../hooks/useCropCycles';
import { useProjects } from '../hooks/useProjects';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import type { OperationalTransaction } from '../types';

export default function TransactionsPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    crop_cycle_id: searchParams.get('crop_cycle_id') || '',
    project_id: searchParams.get('project_id') || '',
    classification: searchParams.get('classification') || '',
    date_from: searchParams.get('date_from') || '',
    date_to: searchParams.get('date_to') || '',
  });

  const { data: transactions, isLoading } = useOperationalTransactions(filters);
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects();
  const { hasRole } = useRole();
  const { formatDate } = useFormatting();

  const canCreate = hasRole(['tenant_admin', 'accountant', 'operator']);

  const handleFilterChange = (key: string, value: string) => {
    const newFilters = { ...filters, [key]: value };
    setFilters(newFilters);
    const params = new URLSearchParams();
    Object.entries(newFilters).forEach(([k, v]) => {
      if (v) params.set(k, v);
    });
    setSearchParams(params);
  };

  const columns: Column<OperationalTransaction>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.transaction_date) },
    { header: 'Type', accessor: 'type' },
    { header: 'Amount', accessor: 'amount' },
    { header: 'Classification', accessor: 'classification' },
    {
      header: 'Project',
      accessor: (row) => row.project?.name || 'N/A',
    },
    {
      header: 'Crop Cycle',
      accessor: (row) => row.crop_cycle?.name || 'N/A',
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <Link
          to={`/app/transactions/${row.id}`}
          className="text-[#1F6F5C] hover:text-[#1a5a4a]"
        >
          View
        </Link>
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
        <h1 className="text-2xl font-bold text-gray-900">Operational Transactions</h1>
        {canCreate && (
          <Link
            to="/app/transactions/new"
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Transaction
          </Link>
        )}
      </div>

      <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4 mb-6">
        <p className="text-sm text-[#2D3A3A]">
          <strong>Note:</strong> Draft transactions do not appear in reports until posted.
        </p>
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Classification</label>
            <select
              value={filters.classification}
              onChange={(e) => handleFilterChange('classification', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="SHARED">Shared</option>
              <option value="HARI_ONLY">HARI Only</option>
              <option value="FARM_OVERHEAD">Farm Overhead</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Date From</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => handleFilterChange('date_from', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Date To</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => handleFilterChange('date_to', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={transactions || []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/transactions/${row.id}`)}
        />
      </div>
    </div>
  );
}
