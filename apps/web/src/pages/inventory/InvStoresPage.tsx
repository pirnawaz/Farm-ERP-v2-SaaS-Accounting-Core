import { useState } from 'react';
import { useInventoryStores, useCreateStore } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { InvStore } from '../../types';

export default function InvStoresPage() {
  const { data: stores, isLoading } = useInventoryStores();
  const createM = useCreateStore();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ name: '', type: 'MAIN' as 'MAIN'|'FIELD'|'OTHER', is_active: true });

  const cols: Column<InvStore>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Type', accessor: 'type' },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
  ];

  const handleCreate = async () => {
    if (!form.name) return;
    await createM.mutateAsync({ name: form.name, type: form.type, is_active: form.is_active });
    setShowModal(false);
    setForm({ name: '', type: 'MAIN', is_active: true });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div className="space-y-6">
      <PageHeader
        title="Stores"
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory', to: '/app/inventory' }, { label: 'Stores' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Store</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={stores || []} columns={cols} emptyMessage="No stores. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New Store">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required><input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Type">
            <select value={form.type} onChange={e => setForm(f => ({ ...f, type: e.target.value as 'MAIN'|'FIELD'|'OTHER' }))} className="w-full px-3 py-2 border rounded">
              <option value="MAIN">MAIN</option><option value="FIELD">FIELD</option><option value="OTHER">OTHER</option>
            </select>
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onClick={() => setShowModal(false)} className="w-full sm:w-auto px-4 py-2 border rounded">Cancel</button>
            <button type="button" onClick={handleCreate} disabled={!form.name || createM.isPending} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded">Create</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
