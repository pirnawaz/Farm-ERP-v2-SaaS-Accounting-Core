import { useState } from 'react';
import { useStockMovements, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { InvStockMovement } from '../../types';

export default function StockMovementsPage() {
  const [storeId, setStoreId] = useState('');
  const [itemId, setItemId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const { data: movements, isLoading } = useStockMovements({
    store_id: storeId || undefined,
    item_id: itemId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { formatMoney, formatDateTime } = useFormatting();

  const cols: Column<InvStockMovement>[] = [
    { header: 'Occurred at', accessor: (r) => formatDateTime(r.occurred_at) },
    { header: 'Type', accessor: 'movement_type' },
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
    { header: 'Item', accessor: (r) => r.item?.name || r.item_id },
    { header: 'Qty delta', accessor: (r) => String(r.qty_delta) },
    { header: 'Value delta', accessor: (r) => formatMoney(r.value_delta) },
    { header: 'Source', accessor: (r) => `${r.source_type} ${r.source_id}` },
  ];

  return (
    <div>
      <PageHeader title="Stock Movements" backTo="/app/inventory" breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'Stock Movements' }]} />
      <div className="flex flex-wrap gap-4 mb-4">
        <select value={storeId} onChange={(e) => setStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All stores</option>
          {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={itemId} onChange={(e) => setItemId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All items</option>
          {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
        </select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} placeholder="From" className="px-3 py-2 border rounded text-sm" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} placeholder="To" className="px-3 py-2 border rounded text-sm" />
      </div>
      <div className="bg-white rounded-lg shadow">
        {isLoading ? (
          <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
        ) : (
          <DataTable data={movements || []} columns={cols} emptyMessage="No stock movements. Post GRNs or Issues." />
        )}
      </div>
    </div>
  );
}
