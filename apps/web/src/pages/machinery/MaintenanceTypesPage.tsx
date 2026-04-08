import { useMemo, useState } from 'react';
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
import { Badge } from '../../components/Badge';

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
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.is_active ? 'success' : 'neutral'}>
          {r.is_active ? 'Active' : 'Inactive'}
        </Badge>
      ),
    },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          type="button"
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

  const totalCount = useMemo(() => (maintenanceTypes ?? []).length, [maintenanceTypes]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Maintenance Setup"
        tooltip="Define maintenance types and configurations for your machines."
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Machinery Overview', to: '/app/machinery' }, { label: 'Maintenance Setup' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
          >
            Add maintenance type
          </button>
        ) : undefined}
      />
      <div className="space-y-1">
        <p className="text-sm text-gray-600">Define maintenance types and configurations for your machines.</p>
        <p className="text-xs text-gray-500">Use maintenance types to organise maintenance jobs.</p>
      </div>
      <div className="text-sm text-gray-600">
        <span className="font-medium text-gray-900 tabular-nums">{totalCount}</span>{' '}
        {totalCount === 1 ? 'maintenance type' : 'maintenance types'}
      </div>
      <div className="bg-white rounded-lg shadow overflow-x-auto">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <LoadingSpinner size="lg" />
          </div>
        ) : (
          <DataTable data={maintenanceTypes || []} columns={cols} emptyMessage="No maintenance types yet. Add one to organise maintenance jobs." />
        )}
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingType ? 'Edit Maintenance Type' : 'New Maintenance Type'}>
        <div className="space-y-4">
          {!editingType && (
            <p className="text-sm text-gray-500">e.g. Oil Change, Belt Replacement, Engine Service…</p>
          )}
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
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button type="button" onClick={handleCloseModal} className="w-full sm:w-auto px-4 py-2 border rounded">
              Cancel
            </button>
            {editingType ? (
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
