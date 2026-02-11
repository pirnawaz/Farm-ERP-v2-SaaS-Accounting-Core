import { useState } from 'react';
import {
  useMachinesQuery,
  useCreateMachine,
  useUpdateMachine,
} from '../../hooks/useMachinery';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import type { Machine, CreateMachinePayload, UpdateMachinePayload } from '../../types';

export default function MachinesPage() {
  const { data: machines, isLoading } = useMachinesQuery();
  const createM = useCreateMachine();
  const updateM = useUpdateMachine();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [editingMachine, setEditingMachine] = useState<Machine | null>(null);
  type FormState = Omit<CreateMachinePayload, 'opening_meter'> & { opening_meter: string };
  const [form, setForm] = useState<FormState>({
    code: '',
    name: '',
    machine_type: '',
    ownership_type: '',
    is_active: true,
    meter_unit: 'HOURS',
    opening_meter: '',
    notes: null,
  });

  const cols: Column<Machine>[] = [
    { header: 'Code', accessor: 'code' },
    { header: 'Name', accessor: 'name' },
    { header: 'Type', accessor: 'machine_type' },
    { header: 'Ownership', accessor: 'ownership_type' },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
    { header: 'Meter Unit', accessor: 'meter_unit' },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setEditingMachine(r);
            setForm({
              code: r.code,
              name: r.name,
              machine_type: r.machine_type,
              ownership_type: r.ownership_type,
              is_active: r.is_active ?? true,
              meter_unit: r.meter_unit,
              opening_meter: r.opening_meter != null && r.opening_meter !== '' ? String(r.opening_meter) : '',
              notes: r.notes || null,
            });
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
    if (!form.name.trim() || !form.machine_type || !form.ownership_type) return;
    const payload: CreateMachinePayload = {
      code: form.code?.trim() || undefined,
      name: form.name.trim(),
      machine_type: form.machine_type,
      ownership_type: form.ownership_type,
      is_active: form.is_active ?? true,
      meter_unit: form.meter_unit,
      opening_meter: parseFloat(form.opening_meter) || 0,
      notes: form.notes,
    };
    await createM.mutateAsync(payload);
    setShowModal(false);
    resetForm();
  };

  const handleUpdate = async () => {
    if (!editingMachine || !form.name.trim() || !form.machine_type || !form.ownership_type) return;
    const payload: UpdateMachinePayload = {
      name: form.name.trim(),
      machine_type: form.machine_type,
      ownership_type: form.ownership_type,
      is_active: form.is_active ?? true,
      meter_unit: form.meter_unit,
      opening_meter: parseFloat(form.opening_meter) || 0,
      notes: form.notes,
    };
    await updateM.mutateAsync({ id: editingMachine.id, payload });
    setShowModal(false);
    setEditingMachine(null);
    resetForm();
  };

  const handleCloseModal = () => {
    setShowModal(false);
    setEditingMachine(null);
    resetForm();
  };

  const resetForm = () => {
    setForm({
      code: '',
      name: '',
      machine_type: '',
      ownership_type: '',
      is_active: true,
      meter_unit: 'HOURS',
      opening_meter: '',
      notes: null,
    });
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Machines"
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Machinery', to: '/app/machinery' }, { label: 'Machines' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Machine</button>
        ) : undefined}
      />
      <div className="bg-white rounded-lg shadow">
        <DataTable data={machines || []} columns={cols} emptyMessage="No machines. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingMachine ? 'Edit Machine' : 'New Machine'}>
        <div className="space-y-4">
          <FormField label="Code">
            <input
              value={form.code ?? ''}
              onChange={e => setForm(f => ({ ...f, code: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="Auto-generated if blank"
              readOnly={!!editingMachine}
              disabled={!!editingMachine}
            />
          </FormField>
          <FormField label="Name" required>
            <input
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="Enter machine name"
            />
          </FormField>
          <FormField label="Machine Type" required>
            <input
              value={form.machine_type}
              onChange={e => setForm(f => ({ ...f, machine_type: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="e.g., Tractor, Harvester"
            />
          </FormField>
          <FormField label="Ownership Type" required>
            <input
              value={form.ownership_type}
              onChange={e => setForm(f => ({ ...f, ownership_type: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="e.g., Owned, Leased"
            />
          </FormField>
          <FormField label="Active">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={form.is_active ?? true}
                onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))}
              />
              <span>Active</span>
            </label>
          </FormField>
          <FormField label="Meter Unit" required>
            <select
              value={form.meter_unit}
              onChange={e => setForm(f => ({ ...f, meter_unit: e.target.value as 'HOURS' | 'KM' }))}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="HOURS">HOURS</option>
              <option value="KM">KM</option>
            </select>
          </FormField>
          <FormField label="Opening Meter">
            <input
              type="number"
              step="0.01"
              min="0"
              value={form.opening_meter}
              onChange={e => setForm(f => ({ ...f, opening_meter: e.target.value }))}
              className="w-full px-3 py-2 border rounded"
              placeholder="0.00"
            />
          </FormField>
          <FormField label="Notes">
            <textarea
              value={form.notes || ''}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value || null }))}
              className="w-full px-3 py-2 border rounded"
              rows={3}
              placeholder="Optional notes"
            />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={handleCloseModal} className="px-4 py-2 border rounded">Cancel</button>
            {editingMachine ? (
              <button
                onClick={handleUpdate}
                disabled={!form.name.trim() || !form.machine_type || !form.ownership_type || updateM.isPending}
                className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateM.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                onClick={handleCreate}
                disabled={!form.name.trim() || !form.machine_type || !form.ownership_type || createM.isPending}
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
