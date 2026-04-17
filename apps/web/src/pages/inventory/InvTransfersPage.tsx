import { useState, useMemo } from 'react';
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

  const sortedTransfers = useMemo(() => {
    const g = [...(transfers ?? [])];
    g.sort((a, b) => {
      const da = a.status === 'DRAFT' ? 0 : 1;
      const db = b.status === 'DRAFT' ? 0 : 1;
      if (da !== db) return da - db;
      return String(b.doc_date ?? '').localeCompare(String(a.doc_date ?? ''));
    });
    return g;
  }, [transfers]);

  const cols: Column<InvTransfer>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'From', accessor: (r) => r.from_store?.name || r.from_store_id },
    { header: 'To', accessor: (r) => r.to_store?.name || r.to_store_id },
    { header: 'Doc Date', accessor: (r) => formatDate(r.doc_date) },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status === 'DRAFT' ? 'Draft' : r.status === 'POSTED' ? 'Posted' : r.status}
        </Badge>
      ),
    },
    {
      header: 'Quick actions',
      accessor: (r) => (
        <div className="flex flex-wrap gap-2" onClick={(e) => e.stopPropagation()}>
          {r.status === 'DRAFT' && hasRole(['tenant_admin', 'accountant', 'operator']) ? (
            <button
              type="button"
              className="text-sm font-medium text-[#1F6F5C] hover:underline"
              onClick={() => navigate(`/app/inventory/transfers/${r.id}`, { state: { from: location.pathname + location.search } })}
            >
              Continue editing
            </button>
          ) : null}
          <button
            type="button"
            className="text-sm font-medium text-gray-700 hover:underline"
            onClick={() => navigate(`/app/inventory/transfers/${r.id}`, { state: { from: location.pathname + location.search } })}
          >
            View
          </button>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={term('transfer')}
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory Overview', to: '/app/inventory' }, { label: term('transfer') }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button type="button" onClick={() => navigate('/app/inventory/transfers/new')} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New stock transfer</button>
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
            <DataTable data={sortedTransfers as InvTransfer[]} columns={cols} onRowClick={(r) => navigate(`/app/inventory/transfers/${r.id}`, { state: { from: location.pathname + location.search } })} emptyMessage="No stock transfers yet. Move stock between storage locations when you relocate items." />
          )}
        </div>
      </div>
    </div>
  );
}
