import { Link } from 'react-router-dom';
import { useStockOnHand } from '../../hooks/useInventory';
import { term } from '../../config/terminology';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { InvStockBalance } from '../../types';

export default function InventoryDashboardPage() {
  const { data: stock, isLoading } = useStockOnHand({});
  const { formatMoney } = useFormatting();

  const columns: Column<InvStockBalance>[] = [
    { header: 'Store', accessor: (r) => r.store?.name || r.store_id },
    { header: term('inventoryItemSingular'), accessor: (r) => r.item?.name || r.item_id },
    { header: 'Qty', accessor: (r) => String(r.qty_on_hand) },
    { header: 'Value', accessor: (r) => <span className="tabular-nums">{formatMoney(r.value_on_hand)}</span> },
    { header: 'WAC', accessor: (r) => <span className="tabular-nums">{formatMoney(r.wac_cost)}</span> },
  ];

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Inventory</h1>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <Link to="/app/inventory/items" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('inventoryItem')}</span>
          <p className="text-sm text-gray-500">Manage {term('inventoryItem').toLowerCase()}</p>
        </Link>
        <Link to="/app/inventory/uoms" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">UoMs</span>
          <p className="text-sm text-gray-500">Units of measure (KG, BAG, L…)</p>
        </Link>
        <Link to="/app/inventory/stores" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">Stores</span>
          <p className="text-sm text-gray-500">Warehouses and locations</p>
        </Link>
        <Link to="/app/inventory/categories" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('inventoryCategory')}</span>
          <p className="text-sm text-gray-500">{term('inventoryItemSingular')} categories</p>
        </Link>
        <Link to="/app/inventory/grns" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('grn')}</span>
          <p className="text-sm text-gray-500">Goods received</p>
        </Link>
        <Link to="/app/inventory/issues" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('issue')}</span>
          <p className="text-sm text-gray-500">Stock issued to projects</p>
        </Link>
        <Link to="/app/inventory/transfers" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('transfer')}</span>
          <p className="text-sm text-gray-500">Store-to-store moves</p>
        </Link>
        <Link to="/app/inventory/adjustments" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('adjustment')}</span>
          <p className="text-sm text-gray-500">Loss, damage, count variance</p>
        </Link>
        <Link to="/app/inventory/stock-on-hand" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('stockOnHand')}</span>
          <p className="text-sm text-gray-500">Balances by store/{term('inventoryItemSingular').toLowerCase()}</p>
        </Link>
        <Link to="/app/inventory/stock-movements" className="p-4 bg-white rounded-lg shadow border border-gray-200 hover:border-[#1F6F5C]/30">
          <span className="font-medium text-gray-900">{term('stockMovements')}</span>
          <p className="text-sm text-gray-500">Movement history</p>
        </Link>
      </div>
      <div className="bg-white rounded-lg shadow">
        <h2 className="text-lg font-medium text-gray-900 p-4 border-b">{term('stockOnHand')}</h2>
        {isLoading ? (
          <div className="flex justify-center py-12"><LoadingSpinner /></div>
        ) : (
          <DataTable data={stock || []} columns={columns} emptyMessage={`No stock. Create ${term('inventoryItem').toLowerCase()}, stores, and record ${term('grn')}.`} />
        )}
      </div>
    </div>
  );
}
