import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useSales } from '../hooks/useSales';
import { useParties } from '../hooks/useParties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import type { Sale } from '../types';

export default function SalesPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    buyer_party_id: searchParams.get('buyer_party_id') || '',
    project_id: searchParams.get('project_id') || '',
    date_from: searchParams.get('date_from') || '',
    date_to: searchParams.get('date_to') || '',
  });

  const { data: sales, isLoading } = useSales(filters);
  const { data: parties } = useParties();
  const { hasRole } = useRole();
  const { formatMoney } = useFormatting();

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

  const columns: Column<Sale>[] = [
    { header: 'Date', accessor: 'posting_date' },
    {
      header: 'Buyer',
      accessor: (row) => row.buyer_party?.name || 'N/A',
    },
    {
      header: 'Project',
      accessor: (row) => row.project?.name || 'Unassigned',
    },
    { 
      header: 'Amount', 
      accessor: (row) => formatMoney(row.amount)
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <Link
          to={`/app/sales/${row.id}`}
          className="text-blue-600 hover:text-blue-900"
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
        <h1 className="text-2xl font-bold text-gray-900">Sales</h1>
        {canCreate && (
          <Link
            to="/app/sales/new"
            className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            New Sale
          </Link>
        )}
      </div>

      <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <p className="text-sm text-blue-800">
          <strong>Note:</strong> Sales create receivables (buyers owe us). They affect accounting only after POST.
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
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Buyer</label>
            <select
              value={filters.buyer_party_id}
              onChange={(e) => handleFilterChange('buyer_party_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">All</option>
              {parties?.map((party) => (
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
              value={filters.date_from}
              onChange={(e) => handleFilterChange('date_from', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Date To</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => handleFilterChange('date_to', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={sales || []}
          columns={columns}
          onRowClick={(row) => navigate(`/app/sales/${row.id}`)}
        />
      </div>
    </div>
  );
}
