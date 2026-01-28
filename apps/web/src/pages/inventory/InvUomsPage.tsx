import { useState } from 'react';
import { useUoms, useCreateUom } from '../../hooks/useInventory';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { InvUom } from '../../types';

export default function InvUomsPage() {
  const { data: uoms, isLoading } = useUoms();
  const createM = useCreateUom();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ code: '', name: '' });

  const cols: Column<InvUom>[] = [
    { header: 'Code', accessor: 'code' },
    { header: 'Name', accessor: 'name' },
  ];

  const handleCreate = async () => {
    if (!form.code.trim() || !form.name.trim()) return;
    await createM.mutateAsync({ code: form.code.trim(), name: form.name.trim() });
    setShowModal(false);
    setForm({ code: '', name: '' });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Units of Measure"
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'UoMs' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New UoM</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={uoms || []} columns={cols} emptyMessage="No UoMs. Create one (e.g. KG, BAG, L) so you can add items." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New UoM">
        <div className="space-y-4">
          <FormField label="Code" required>
            <input
              value={form.code}
              onChange={e => setForm(f => ({ ...f, code: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="e.g. KG, BAG, L"
            />
          </FormField>
          <FormField label="Name" required>
            <input
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="e.g. Kilogram, Bag, Liter"
            />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button
              onClick={handleCreate}
              disabled={!form.code.trim() || !form.name.trim() || createM.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {createM.isPending ? 'Creating...' : 'Create'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
