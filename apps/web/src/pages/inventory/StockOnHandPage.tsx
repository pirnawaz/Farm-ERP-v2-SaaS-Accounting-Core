import { useState } from 'react';
import { useStockOnHand, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { InvStockBalance } from '../../types';

export default function StockOnHandPage() {
  const [storeId, setStoreId] = useState('');
  const [itemId, setItemId] = useState('');
  const { data: balances, isLoading } = useStockOnHand({
    store_id: storeId || undefined,
    item_id: itemId || undefined,
  });
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { formatMoney } = useFormatting();

  const cols: Column<InvStockBalance>[] = [
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
    { header: 'Item', accessor: (r) => r.item?.name || r.item_id },
    { header: 'Qty on hand', accessor: (r) => String(r.qty_on_hand) },
    { header: 'Value on hand', accessor: (r) => formatMoney(r.value_on_hand) },
    { header: 'WAC cost', accessor: (r) => formatMoney(r.wac_cost) },
  ];

  return (
    <div>
      <PageHeader title="Stock On Hand" backTo="/app/inventory" breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'Stock On Hand' }]} />
      <div className="flex flex-wrap gap-4 mb-4">
        <select value={storeId} onChange={(e) => setStoreId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All stores</option>
          {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
        <select value={itemId} onChange={(e) => setItemId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All items</option>
          {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
        </select>
      </div>
      <div className="bg-white rounded-lg shadow">
        {isLoading ? (
          <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
        ) : (
          <DataTable data={balances || []} columns={cols} emptyMessage="No stock on hand. Post GRNs to receive stock." />
        )}
      </div>
    </div>
  );
}
