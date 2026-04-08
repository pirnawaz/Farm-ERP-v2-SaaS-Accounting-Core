import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  useInventoryItems,
  useCreateItem,
  useUpdateItem,
  useDeactivateItem,
  useActivateItem,
  useDeleteItem,
  useUoms,
  useCategories,
} from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { useRole } from '../../hooks/useRole';
import { term } from '../../config/terminology';
import type { InvItem } from '../../types';

export default function InvItemsPage() {
  const { data: items, isLoading } = useInventoryItems();
  const { data: uoms } = useUoms();
  const { data: categories } = useCategories();
  const createM = useCreateItem();
  const updateM = useUpdateItem();
  const deactivateM = useDeactivateItem();
  const activateM = useActivateItem();
  const deleteM = useDeleteItem();
  const { hasRole } = useRole();
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [editingItem, setEditingItem] = useState<InvItem | null>(null);
  const [confirmAction, setConfirmAction] = useState<{ action: 'deactivate' | 'activate' | 'delete'; item: InvItem } | null>(null);
  const [form, setForm] = useState({
    name: '',
    sku: '',
    category_id: '' as string,
    uom_id: '',
    valuation_method: 'WAC' as string,
    is_active: true,
  });

  const canAct = hasRole(['tenant_admin', 'accountant', 'operator']);

  const cols: Column<InvItem>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'SKU', accessor: (r) => r.sku || '—' },
    { header: term('inventoryCategorySingular'), accessor: (r) => r.category?.name || '—' },
    { header: 'Unit', accessor: (r) => r.uom?.code || r.uom_id },
    { header: 'Valuation', accessor: 'valuation_method' },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
    ...(canAct
      ? [
          {
            header: 'Actions',
            accessor: (row: InvItem) => (
              <div className="flex flex-wrap gap-x-3 gap-y-1" onClick={(e) => e.stopPropagation()}>
                <button
                  type="button"
                  onClick={() => {
                    setEditingItem(row);
                    setForm({
                      name: row.name,
                      sku: row.sku ?? '',
                      category_id: row.category_id ?? '',
                      uom_id: row.uom_id,
                      valuation_method: row.valuation_method,
                      is_active: row.is_active,
                    });
                  }}
                  className="text-sm text-[#1F6F5C] hover:underline"
                >
                  Edit
                </button>
                {row.is_active ? (
                  <button
                    type="button"
                    onClick={() => setConfirmAction({ action: 'deactivate', item: row })}
                    className="text-sm text-amber-700 hover:underline"
                  >
                    Deactivate
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => setConfirmAction({ action: 'activate', item: row })}
                    className="text-sm text-[#1F6F5C] hover:underline"
                  >
                    Activate
                  </button>
                )}
                {row.can_delete && (
                  <button
                    type="button"
                    onClick={() => setConfirmAction({ action: 'delete', item: row })}
                    className="text-sm text-red-600 hover:underline"
                  >
                    Delete
                  </button>
                )}
              </div>
            ),
          } as Column<InvItem>,
        ]
      : []),
  ];

  const handleCreate = async () => {
    if (!form.name || !form.uom_id) return;
    await createM.mutateAsync({
      name: form.name,
      sku: form.sku || undefined,
      category_id: form.category_id || undefined,
      uom_id: form.uom_id,
      valuation_method: form.valuation_method,
      is_active: form.is_active,
    });
    setShowCreateModal(false);
    setForm({ name: '', sku: '', category_id: '', uom_id: '', valuation_method: 'WAC', is_active: true });
  };

  const handleUpdate = async () => {
    if (!editingItem || !form.name || !form.uom_id) return;
    await updateM.mutateAsync({
      id: editingItem.id,
      payload: {
        name: form.name,
        sku: form.sku || null,
        category_id: form.category_id || null,
        uom_id: form.uom_id,
        valuation_method: form.valuation_method,
        is_active: form.is_active,
      },
    });
    setEditingItem(null);
    setForm({ name: '', sku: '', category_id: '', uom_id: '', valuation_method: 'WAC', is_active: true });
  };

  const handleConfirmAction = async () => {
    if (!confirmAction) return;
    const { action, item } = confirmAction;
    if (action === 'deactivate') await deactivateM.mutateAsync(item.id);
    if (action === 'activate') await activateM.mutateAsync(item.id);
    if (action === 'delete') await deleteM.mutateAsync(item.id);
    setConfirmAction(null);
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div className="space-y-6">
      <PageHeader
        title={term('inventoryItem')}
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory Overview', to: '/app/inventory' }, { label: term('inventoryItem') }]}
        right={canAct ? (
          <button onClick={() => setShowCreateModal(true)} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">Add Item</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={items || []} columns={cols} emptyMessage={`No ${term('inventoryItem').toLowerCase()} yet. Add items you buy or use on the farm.`} />
      </div>

      {/* Create modal */}
      <Modal isOpen={showCreateModal} onClose={() => setShowCreateModal(false)} title="Add Item">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required><input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="SKU"><input value={form.sku} onChange={e => setForm(f => ({ ...f, sku: e.target.value }))} className="w-full px-3 py-2 border rounded" placeholder="Optional" /></FormField>
          <FormField label={term('inventoryCategorySingular')}>
            <select value={form.category_id} onChange={e => setForm(f => ({ ...f, category_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">—</option>
              {categories?.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Unit" required>
            {(uoms && uoms.length === 0) && (
              <p className="text-sm text-amber-700 mb-1">
                No units yet. <Link to="/app/inventory/uoms" className="text-[#1F6F5C] font-medium hover:underline">Create units in Inventory → Units</Link> first.
              </p>
            )}
            <select value={form.uom_id} onChange={e => setForm(f => ({ ...f, uom_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">Select unit</option>
              {uoms?.map(u => <option key={u.id} value={u.id}>{u.code} ({u.name})</option>)}
            </select>
          </FormField>
          <FormField label="Valuation">
            <select value={form.valuation_method} onChange={e => setForm(f => ({ ...f, valuation_method: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="WAC">WAC</option>
              <option value="FIFO">FIFO</option>
            </select>
          </FormField>
          <FormField label="Active">
            <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onClick={() => setShowCreateModal(false)} className="w-full sm:w-auto px-4 py-2 border rounded">Cancel</button>
            <button type="button" onClick={handleCreate} disabled={!form.name || !form.uom_id || createM.isPending} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded">Create</button>
          </div>
        </div>
      </Modal>

      {/* Edit modal */}
      <Modal isOpen={!!editingItem} onClose={() => setEditingItem(null)} title={`Edit ${term('inventoryItemSingular')}`}>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required><input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="SKU"><input value={form.sku} onChange={e => setForm(f => ({ ...f, sku: e.target.value }))} className="w-full px-3 py-2 border rounded" placeholder="Optional" /></FormField>
          <FormField label={term('inventoryCategorySingular')}>
            <select value={form.category_id} onChange={e => setForm(f => ({ ...f, category_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">—</option>
              {categories?.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Unit" required>
            <select value={form.uom_id} onChange={e => setForm(f => ({ ...f, uom_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">Select unit</option>
              {uoms?.map(u => <option key={u.id} value={u.id}>{u.code} ({u.name})</option>)}
            </select>
          </FormField>
          <FormField label="Valuation">
            <select value={form.valuation_method} onChange={e => setForm(f => ({ ...f, valuation_method: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="WAC">WAC</option>
              <option value="FIFO">FIFO</option>
            </select>
          </FormField>
          <FormField label="Active">
            <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onClick={() => setEditingItem(null)} className="w-full sm:w-auto px-4 py-2 border rounded">Cancel</button>
            <button type="button" onClick={handleUpdate} disabled={!form.name || !form.uom_id || updateM.isPending} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
          </div>
        </div>
      </Modal>

      {/* Deactivate / Activate / Delete confirm */}
      <ConfirmDialog
        isOpen={!!confirmAction}
        onClose={() => setConfirmAction(null)}
        onConfirm={handleConfirmAction}
        title={
          confirmAction?.action === 'deactivate'
            ? 'Deactivate item'
            : confirmAction?.action === 'activate'
              ? 'Activate item'
              : 'Delete item'
        }
        message={
          confirmAction?.action === 'deactivate'
            ? `Deactivate "${confirmAction.item.name}"? It will no longer appear in selection lists for new documents.`
            : confirmAction?.action === 'activate'
              ? `Activate "${confirmAction.item.name}"? It will appear in selection lists again.`
              : confirmAction
                ? `Delete "${confirmAction.item.name}"? This cannot be undone.`
                : ''
        }
        confirmText={confirmAction?.action === 'delete' ? 'Delete' : confirmAction?.action === 'deactivate' ? 'Deactivate' : 'Activate'}
        variant={confirmAction?.action === 'delete' ? 'danger' : 'default'}
      />
    </div>
  );
}
