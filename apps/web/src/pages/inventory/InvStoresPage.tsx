import { useState } from 'react';
import { useInventoryStores, useCreateStore } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
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
    <div>
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Stores</h1>
        {hasRole(['tenant_admin', 'accountant', 'operator']) && (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">New Store</button>
        )}
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable data={stores || []} columns={cols} emptyMessage="No stores. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New Store">
        <div className="space-y-4">
          <FormField label="Name" required><input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Type">
            <select value={form.type} onChange={e => setForm(f => ({ ...f, type: e.target.value as 'MAIN'|'FIELD'|'OTHER' }))} className="w-full px-3 py-2 border rounded">
              <option value="MAIN">MAIN</option><option value="FIELD">FIELD</option><option value="OTHER">OTHER</option>
            </select>
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleCreate} disabled={!form.name || createM.isPending} className="px-4 py-2 bg-blue-600 text-white rounded">Create</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
