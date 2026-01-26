import { useState } from 'react';
import { useCategories, useCreateCategory, useUpdateCategory } from '../../hooks/useInventory';
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
          className="px-3 py-1 text-sm text-blue-600 hover:text-blue-800"
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
    <div>
      <PageHeader
        title="Categories"
        backTo="/app/inventory"
        breadcrumbs={[{ label: 'Inventory', to: '/app/inventory' }, { label: 'Categories' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">New Category</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={categories || []} columns={cols} emptyMessage="No categories. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingCategory ? 'Edit Category' : 'New Category'}>
        <div className="space-y-4">
          <FormField label="Name" required>
            <input
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="Enter category name"
            />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={handleCloseModal} className="px-4 py-2 border rounded">Cancel</button>
            {editingCategory ? (
              <button
                onClick={handleUpdate}
                disabled={!form.name.trim() || updateM.isPending}
                className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
              >
                {updateM.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                onClick={handleCreate}
                disabled={!form.name.trim() || createM.isPending}
                className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
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
