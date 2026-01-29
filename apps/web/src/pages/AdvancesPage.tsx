import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useAdvances } from '../hooks/useAdvances';
import { useParties } from '../hooks/useParties';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import type { Advance } from '../types';

export default function AdvancesPage() {
  const { formatMoney, formatDate } = useFormatting();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    type: searchParams.get('type') || '',
    direction: searchParams.get('direction') || '',
    party_id: searchParams.get('party_id') || '',
    date_from: searchParams.get('date_from') || '',
    date_to: searchParams.get('date_to') || '',
  });

  const { data: advances, isLoading } = useAdvances(filters);
  const { data: parties } = useParties();
  const { hasRole } = useRole();

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

  const getTypeLabel = (type: string) => {
    switch (type) {
      case 'HARI_ADVANCE':
        return 'Hari Advance';
      case 'VENDOR_ADVANCE':
        return 'Vendor Advance';
      case 'LOAN':
        return 'Loan';
      default:
        return type;
    }
  };

  const columns: Column<Advance>[] = [
    { header: 'Date', accessor: (row) => formatDate(row.posting_date) },
    {
      header: 'Party',
      accessor: (row) => row.party?.name || 'N/A',
    },
    {
      header: 'Type',
      accessor: (row) => getTypeLabel(row.type),
    },
    { header: 'Direction', accessor: 'direction' },
    { 
      header: 'Amount', 
      accessor: (row) => <span className="tabular-nums">{formatMoney(row.amount)}</span>
    },
    { header: 'Status', accessor: 'status' },
    {
      header: 'Actions',
      accessor: (row) => (
        <Link
          to={`/app/advances/${row.id}`}
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
      <PageHeader
        title="Advances"
        backTo="/app/dashboard"
        breadcrumbs={[{ label: 'Advances' }]}
        right={canCreate ? (
          <Link
            to="/app/advances/new"
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]"
          >
            New Advance
          </Link>
        ) : undefined}
      />

      <div className="bg-[#E6ECEA] border border-[#1F6F5C]/20 rounded-lg p-4 mb-6">
        <p className="text-sm text-[#2D3A3A]">
          <strong>Note:</strong> Advances create receivable balances (assets). They affect accounting only after POST.
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
            <label className="block text-sm font-medium text-gray-700 mb-1">Type</label>
            <select
              value={filters.type}
              onChange={(e) => handleFilterChange('type', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="HARI_ADVANCE">Hari Advance</option>
              <option value="VENDOR_ADVANCE">Vendor Advance</option>
              <option value="LOAN">Loan</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Direction</label>
            <select
              value={filters.direction}
              onChange={(e) => handleFilterChange('direction', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="IN">IN (Repayment)</option>
              <option value="OUT">OUT (Disbursement)</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Party</label>
            <select
              value={filters.party_id}
              onChange={(e) => handleFilterChange('party_id', e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
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
          data={(advances ?? []) as Advance[]}
          columns={columns}
          onRowClick={(row) => navigate(`/app/advances/${row.id}`)}
        />
      </div>
    </div>
  );
}
