import { useState } from 'react';
import { useStockOnHand, useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { term } from '../../config/terminology';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
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
    { header: 'Item', accessor: (r) => formatItemDisplayName(r.item) },
    { header: 'Qty on hand', accessor: (r) => String(r.qty_on_hand) },
    { header: 'Value on hand', accessor: (r) => formatMoney(r.value_on_hand), numeric: true },
    { header: 'WAC cost', accessor: (r) => formatMoney(r.wac_cost), numeric: true },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title={term('stockOnHand')} backTo="/app/inventory" breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory', to: '/app/inventory' }, { label: term('stockOnHand') }]} />
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 items-end">
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
          <DataTable data={balances || []} columns={cols} emptyMessage={`No ${term('stockOnHand').toLowerCase()}. Record ${term('grn')} to receive stock.`} />
        )}
        </div>
      </div>
    </div>
  );
}
