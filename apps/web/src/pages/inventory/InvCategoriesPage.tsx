import { useState } from 'react';
import { useCategories, useCreateCategory, useUpdateCategory } from '../../hooks/useInventory';
import { term } from '../../config/terminology';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { InvItemCategory } from '../../types';

export default function InvCategoriesPage() {
  const { data: categories, isLoading } = useCategories();
  const createM = useCreateCategory();
  const updateM = useUpdateCategory();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [editingCategory, setEditingCategory] = useState<InvItemCategory | null>(null);
  const [form, setForm] = useState({ name: '' });

  const cols: Column<InvItemCategory>[] = [
    { header: 'Name', accessor: 'name' },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setEditingCategory(r);
            setForm({ name: r.name });
            setShowModal(true);
          }}
          className="px-3 py-1 text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
        >
          Edit
        </button>
      ),
    },
  ];

  const handleCreate = async () => {
    if (!form.name.trim()) return;
    await createM.mutateAsync({ name: form.name.trim() });
    setShowModal(false);
    setForm({ name: '' });
  };

  const handleUpdate = async () => {
    if (!editingCategory || !form.name.trim()) return;
    await updateM.mutateAsync({ id: editingCategory.id, payload: { name: form.name.trim() } });
    setShowModal(false);
    setEditingCategory(null);
    setForm({ name: '' });
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingCategory(null);
    setForm({ name: '' });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div className="space-y-6">
      <PageHeader
        title={term('inventoryCategory')}
        tooltip="Group items (e.g. seeds, fertilizer, fuel) for inventory and reporting."
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Inventory Overview', to: '/app/inventory' }, { label: term('inventoryCategory') }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Category</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={categories || []} columns={cols} emptyMessage="No categories yet. Add categories to group items (for example seeds, fuel, or feed)." />
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingCategory ? 'Edit Category' : 'New Category'}>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Name" required className="md:col-span-2">
            <input
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="Enter category name"
            />
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onClick={handleCloseModal} className="w-full sm:w-auto px-4 py-2 border rounded">Cancel</button>
            {editingCategory ? (
              <button
                type="button"
                onClick={handleUpdate}
                disabled={!form.name.trim() || updateM.isPending}
                className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateM.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                type="button"
                onClick={handleCreate}
                disabled={!form.name.trim() || createM.isPending}
                className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {createM.isPending ? 'Creating...' : 'Create'}
              </button>
            )}
          </div>
        </div>
      </Modal>
    </div>
  );
}
