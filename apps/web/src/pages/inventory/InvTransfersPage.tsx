import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useTransfers, useInventoryStores } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { term } from '../../config/terminology';
import { Badge } from '../../components/Badge';
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
  const { formatDate } = useFormatting();

  const cols: Column<InvTransfer>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'From', accessor: (r) => r.from_store?.name || r.from_store_id },
    { header: 'To', accessor: (r) => r.to_store?.name || r.to_store_id },
    { header: 'Doc Date', accessor: (r) => formatDate(r.doc_date) },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status}
        </Badge>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={term('transfer')}
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory', to: '/app/inventory' }, { label: term('transfer') }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button type="button" onClick={() => navigate('/app/inventory/transfers/new')} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New {term('transferSingular')}</button>
        ) : undefined}
      />
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 items-end">
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
          {isLoading ? (
            <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
          ) : (
            <DataTable data={(transfers ?? []) as InvTransfer[]} columns={cols} onRowClick={(r) => navigate(`/app/inventory/transfers/${r.id}`, { state: { from: location.pathname + location.search } })} emptyMessage={`No ${term('transfer')}. Create one.`} />
          )}
        </div>
      </div>
    </div>
  );
}
