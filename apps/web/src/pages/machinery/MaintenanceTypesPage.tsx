import { useState } from 'react';
import {
  useMaintenanceTypesQuery,
  useCreateMaintenanceType,
  useUpdateMaintenanceType,
} from '../../hooks/useMachinery';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { MachineMaintenanceType } from '../../types';

export default function MaintenanceTypesPage() {
  const { data: maintenanceTypes, isLoading } = useMaintenanceTypesQuery();
  const createM = useCreateMaintenanceType();
  const updateM = useUpdateMaintenanceType();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [editingType, setEditingType] = useState<MachineMaintenanceType | null>(null);
  const [form, setForm] = useState({ name: '', is_active: true });

  const cols: Column<MachineMaintenanceType>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setEditingType(r);
            setForm({ name: r.name, is_active: r.is_active });
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
    await createM.mutateAsync({ name: form.name.trim(), is_active: form.is_active });
    setShowModal(false);
    resetForm();
  };

  const handleUpdate = async () => {
    if (!editingType || !form.name.trim()) return;
    await updateM.mutateAsync({
      id: editingType.id,
      payload: { name: form.name.trim(), is_active: form.is_active },
    });
    setShowModal(false);
    setEditingType(null);
    resetForm();
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingType(null);
    resetForm();
  };

  const resetForm = () => {
    setForm({ name: '', is_active: true });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Maintenance Types"
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Machinery', to: '/app/machinery' }, { label: 'Maintenance Types' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Maintenance Type</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={maintenanceTypes || []} columns={cols} emptyMessage="No maintenance types. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingType ? 'Edit Maintenance Type' : 'New Maintenance Type'}>
        <div className="space-y-4">
          <FormField label="Name" required>
            <input
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="Enter maintenance type name"
            />
          </FormField>
          <FormField label="Active">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))}
              />
              <span>Active</span>
            </label>
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={handleCloseModal} className="px-4 py-2 border rounded">Cancel</button>
            {editingType ? (
              <button
                onClick={handleUpdate}
                disabled={!form.name.trim() || updateM.isPending}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateM.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                onClick={handleCreate}
                disabled={!form.name.trim() || createM.isPending}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
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
