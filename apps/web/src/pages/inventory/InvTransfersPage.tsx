import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useTransfers, useInventoryStores } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import type { InvTransfer } from '../../types';

export default function InvTransfersPage() {
  const [status, setStatus] = useState<string>('');
  const [fromStoreId, setFromStoreId] = useState<string>('');
  const [toStoreId, setToStoreId] = useState<string>('');
  const { data: transfers, isLoading } = useTransfers({
    status: status || undefined,
    from_store_id: fromStoreId || undefined,
    to_store_id: toStoreId || undefined,
  });
  const { data: stores } = useInventoryStores();
  const navigate = useNavigate();
  const location = useLocation();
  const { hasRole } = useRole();

  const cols: Column<InvTransfer>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'From', accessor: (r) => r.from_store?.name || r.from_store_id },
    { header: 'To', accessor: (r) => r.to_store?.name || r.to_store_id },
    { header: 'Doc Date', accessor: (r) => formatDate(r.doc_date) },
    {
      header: 'Status',
      accessor: (r) => (
        <span className={`px-2 py-1 rounded text-xs ${r.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : r.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>{r.status}</span>
      ),
    },
  ];

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Transfers"
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'Transfers' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => navigate('/app/inventory/transfers/new')} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Transfer</button>
        ) : undefined}
      />
      <div className="flex gap-4 mb-4">
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All statuses</option>
          <option value="DRAFT">DRAFT</option>
          <option value="POSTED">POSTED</option>
          <option value="REVERSED">REVERSED</option>
        </select>
        <select value={fromStoreId} onChange={(e) => setFromStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">From: all</option>
          {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={toStoreId} onChange={(e) => setToStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">To: all</option>
          {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable data={transfers || []} columns={cols} onRowClick={(r) => navigate(`/app/inventory/transfers/${r.id}`, { state: { from: location.pathname + location.search } })} emptyMessage="No transfers. Create one." />
      </div>
    </div>
  );
}
