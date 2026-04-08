import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useGRNs, useInventoryStores } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { term } from '../../config/terminology';
import { Badge } from '../../components/Badge';
import type { InvGrn } from '../../types';

export default function InvGrnsPage() {
  const [status, setStatus] = useState<string>('');
  const [storeId, setStoreId] = useState<string>('');
  const { data: grns, isLoading } = useGRNs({ status: status || undefined, store_id: storeId || undefined });
  const { data: stores } = useInventoryStores();
  const navigate = useNavigate();
  const location = useLocation();
  const { hasRole } = useRole();
  const { formatDate } = useFormatting();

  const cols: Column<InvGrn>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
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
        title={term('grn')}
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory Overview', to: '/app/inventory' }, { label: term('grn') }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button type="button" onClick={() => navigate('/app/inventory/grns/new')} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Goods Received</button>
        ) : undefined}
      />
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 items-end">
          <select value={status} onChange={e => setStatus(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All statuses</option>
            <option value="DRAFT">DRAFT</option>
            <option value="POSTED">POSTED</option>
            <option value="REVERSED">REVERSED</option>
          </select>
          <select value={storeId} onChange={e => setStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All stores</option>
            {stores?.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        </div>
        <div className="bg-white rounded-lg shadow">
          {isLoading ? (
            <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
          ) : (
            <DataTable
              data={(grns ?? []) as InvGrn[]}
              columns={cols}
              onRowClick={(r) => navigate(`/app/inventory/grns/${r.id}`, { state: { from: location.pathname + location.search } })}
              emptyMessage="Nothing here yet. When stock arrives on the farm, use New Goods Received to record it."
            />
          )}
        </div>
      </div>
    </div>
  );
}
