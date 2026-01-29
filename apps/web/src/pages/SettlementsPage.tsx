import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { salesSettlementApi, type SalesSettlement } from '../api/settlement';
import { DataTable, type Column } from '../components/DataTable';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';

export default function SettlementsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: searchParams.get('status') || '',
    crop_cycle_id: searchParams.get('crop_cycle_id') || '',
  });

  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const canCreate = hasRole(['tenant_admin', 'accountant']);

  const { data: settlements, isLoading } = useQuery({
    queryKey: ['settlements', filters],
    queryFn: () => salesSettlementApi.list(filters),
  });

  const handleFilterChange = (key: string, value: string) => {
    const newFilters = { ...filters, [key]: value };
    setFilters(newFilters);
    const params = new URLSearchParams();
    Object.entries(newFilters).forEach(([k, v]) => {
      if (v) params.set(k, v);
    });
    setSearchParams(params);
  };

  const columns: Column<SalesSettlement>[] = [
    { header: 'Settlement No', accessor: 'settlement_no' },
    { header: 'Status', accessor: (row) => (
      <span className={`px-2 py-1 rounded text-xs ${
        row.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
        row.status === 'POSTED' ? 'bg-green-100 text-green-800' :
        'bg-red-100 text-red-800'
      }`}>
        {row.status}
      </span>
    )},
    { header: 'Basis Amount', accessor: (row) => <span className="tabular-nums">{formatMoney(parseFloat(row.basis_amount))}</span> },
    { header: 'Posting Date', accessor: (row) => (row.posting_date != null ? formatDate(row.posting_date) : '—') },
    { header: 'Share Rule', accessor: (row) => row.share_rule?.name || '-' },
    {
      header: 'Actions',
      accessor: (row) => (
        <Link
          to={`/app/settlements/${row.id}`}
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
        title="Settlements"
        backTo="/app/dashboard"
        breadcrumbs={[{ label: 'Settlements' }]}
        right={canCreate ? (
          <Link
            to="/app/settlements/new"
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
          >
            New Settlement
          </Link>
        ) : undefined}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select
              value={filters.status}
              onChange={(e) => handleFilterChange('status', e.target.value)}
              className="w-full border rounded px-3 py-2"
            >
              <option value="">All</option>
              <option value="DRAFT">DRAFT</option>
              <option value="POSTED">POSTED</option>
              <option value="REVERSED">REVERSED</option>
            </select>
          </div>
        </div>
      </div>

      <div className="bg-white rounded-lg shadow">
        <DataTable data={settlements || []} columns={columns} />
      </div>
    </div>
  );
}
