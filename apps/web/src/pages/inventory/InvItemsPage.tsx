import { useState } from 'react';
import { useInventoryItems, useCreateItem, useUoms, useCategories } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { InvItem } from '../../types';

export default function InvItemsPage() {
  const { data: items, isLoading } = useInventoryItems();
  const { data: uoms } = useUoms();
  const { data: categories } = useCategories();
  const createM = useCreateItem();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({
    name: '',
    sku: '',
    category_id: '' as string,
    uom_id: '',
    valuation_method: 'WAC',
    is_active: true,
  });

  const cols: Column<InvItem>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'SKU', accessor: (r) => r.sku || '—' },
    { header: 'Category', accessor: (r) => r.category?.name || '—' },
    { header: 'UoM', accessor: (r) => r.uom?.code || r.uom_id },
    { header: 'Valuation', accessor: 'valuation_method' },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
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
    setShowModal(false);
    setForm({ name: '', sku: '', category_id: '', uom_id: '', valuation_method: 'WAC', is_active: true });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Items"
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'Items' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Item</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={items || []} columns={cols} emptyMessage="No items. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New Item">
        <div className="space-y-4">
          <FormField label="Name" required><input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="SKU"><input value={form.sku} onChange={e => setForm(f => ({ ...f, sku: e.target.value }))} className="w-full px-3 py-2 border rounded" placeholder="Optional" /></FormField>
          <FormField label="Category">
            <select value={form.category_id} onChange={e => setForm(f => ({ ...f, category_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">—</option>
              {categories?.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Unit of Measure" required>
            <select value={form.uom_id} onChange={e => setForm(f => ({ ...f, uom_id: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="">Select UoM</option>
              {uoms?.map(u => <option key={u.id} value={u.id}>{u.code} ({u.name})</option>)}
            </select>
          </FormField>
          <FormField label="Valuation">
            <select value={form.valuation_method} onChange={e => setForm(f => ({ ...f, valuation_method: e.target.value }))} className="w-full px-3 py-2 border rounded">
              <option value="WAC">WAC</option>
            </select>
          </FormField>
          <FormField label="Active">
            <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleCreate} disabled={!form.name || !form.uom_id || createM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Create</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
