import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
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

  const rows = useMemo(() => (balances ?? []) as InvStockBalance[], [balances]);

  const uniqueStoresInResult = useMemo(() => {
    const s = new Set<string>();
    rows.forEach((r) => s.add(String(r.store_id)));
    return s.size;
  }, [rows]);

  const hasFilters = !!(storeId || itemId);

  const summaryLine = useMemo(() => {
    const n = rows.length;
    const label = n === 1 ? 'stock line' : 'stock lines';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (n === 0) return base;
    const storePart = uniqueStoresInResult
      ? ` · ${uniqueStoresInResult} store${uniqueStoresInResult === 1 ? '' : 's'}`
      : '';
    return `${base}${storePart}`;
  }, [rows.length, uniqueStoresInResult, hasFilters]);

  const clearFilters = () => {
    setStoreId('');
    setItemId('');
  };

  const cols: Column<InvStockBalance>[] = [
    { header: 'Item', accessor: (r) => formatItemDisplayName(r.item) },
    { header: 'Category', accessor: (r) => r.item?.category?.name || '—' },
    { header: 'Store / location', accessor: (r) => r.store?.name || r.store_id },
    { header: 'Unit', accessor: (r) => r.item?.uom?.code || '—' },
    { header: 'Qty on hand', accessor: (r) => String(r.qty_on_hand), numeric: true },
    { header: 'Value on hand', accessor: (r) => formatMoney(r.value_on_hand), numeric: true },
    { header: 'Avg cost (WAC)', accessor: (r) => formatMoney(r.wac_cost), numeric: true },
  ];

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title={term('stockOnHand')}
        tooltip="View current stock levels across items and storage locations."
        description="View current stock levels across items and storage locations."
        backTo="/app/inventory"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('stockOnHand') },
        ]}
      />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:max-w-2xl">
          <div>
            <label htmlFor="soh-store" className="block text-xs font-medium text-gray-600 mb-1">
              Store
            </label>
            <select
              id="soh-store"
              value={storeId}
              onChange={(e) => setStoreId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All stores</option>
              {stores?.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="soh-item" className="block text-xs font-medium text-gray-600 mb-1">
              Item
            </label>
            <select
              id="soh-item"
              value={itemId}
              onChange={(e) => setItemId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All items</option>
              {items?.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : rows.length === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No stock lines yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Stock appears here after you set up items and stores, then record goods received.
          </p>
          <ol className="mt-4 list-decimal list-inside text-sm text-gray-700 text-left max-w-sm mx-auto space-y-1">
            <li>Add items</li>
            <li>Add stores</li>
            <li>Record goods received</li>
          </ol>
          <div className="mt-6 flex flex-wrap gap-2 justify-center">
            <Link
              to="/app/inventory/items"
              className="inline-flex items-center rounded-lg bg-[#1F6F5C] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add items
            </Link>
            <Link
              to="/app/inventory/stores"
              className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50"
            >
              Add stores
            </Link>
            <Link
              to="/app/inventory/grns/new"
              className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-800 hover:bg-gray-50"
            >
              New goods received
            </Link>
          </div>
        </div>
      ) : rows.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No stock lines match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try different store or item filters, or clear filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={rows} columns={cols} emptyMessage="" />
        </div>
      )}
    </div>
  );
}
