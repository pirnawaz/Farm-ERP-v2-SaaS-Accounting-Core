import { useMemo, useState } from 'react';
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
import { Badge } from '../../components/Badge';

export default function MachinesPage() {
  const { data: machines, isLoading } = useMachinesQuery();
  const createM = useCreateMachine();
  const updateM = useUpdateMachine();
  const { hasRole } = useRole();
  const [showModal, setShowModal] = useState(false);
  const [editingMachine, setEditingMachine] = useState<Machine | null>(null);
  const [filters, setFilters] = useState({ query: '', type: '', status: '' });
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

  const allMachines = machines ?? [];
  const machineTypes = useMemo(() => {
    const set = new Set<string>();
    allMachines.forEach((m) => {
      const t = (m.machine_type ?? '').trim();
      if (t) set.add(t);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [allMachines]);

  const filteredMachines = useMemo(() => {
    const q = filters.query.trim().toLowerCase();
    return allMachines.filter((m) => {
      const matchesQuery = !q
        || (m.name ?? '').toLowerCase().includes(q)
        || (m.code ?? '').toLowerCase().includes(q);
      const matchesType = !filters.type || m.machine_type === filters.type;
      const matchesStatus =
        !filters.status
        || (filters.status === 'ACTIVE' ? m.is_active : !m.is_active);
      return matchesQuery && matchesType && matchesStatus;
    });
  }, [allMachines, filters.query, filters.type, filters.status]);

  const hasFilters = !!(filters.query.trim() || filters.type || filters.status);

  const clearFilters = () => setFilters({ query: '', type: '', status: '' });

  const summaryLine = useMemo(() => {
    const n = filteredMachines.length;
    const label = n === 1 ? 'machine' : 'machines';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    return base;
  }, [filteredMachines.length, hasFilters]);

  const cols: Column<Machine>[] = [
    {
      header: 'Machine',
      accessor: (r) => (
        <div className="min-w-[12rem]">
          <div className="font-medium text-gray-900">{r.name}</div>
          <div className="text-xs text-gray-500 tabular-nums">{r.code || '—'}</div>
        </div>
      ),
    },
    { header: 'Type', accessor: (r) => r.machine_type || '—' },
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
          className="text-sm font-medium text-[#1F6F5C] hover:text-[#1a5a4a]"
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

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Machines"
        tooltip="View and manage your machines and equipment."
        description="View and manage your machines and equipment."
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Machinery Overview', to: '/app/machinery' }, { label: 'Machines' }]}
        right={hasRole(['tenant_admin', 'accountant', 'operator']) ? (
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Add machine
          </button>
        ) : undefined}
      />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label htmlFor="mach-search" className="block text-xs font-medium text-gray-600 mb-1">
              Search
            </label>
            <input
              id="mach-search"
              value={filters.query}
              onChange={(e) => setFilters((f) => ({ ...f, query: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              placeholder="Name or code"
            />
          </div>
          <div>
            <label htmlFor="mach-type" className="block text-xs font-medium text-gray-600 mb-1">
              Type
            </label>
            <select
              id="mach-type"
              value={filters.type}
              onChange={(e) => setFilters((f) => ({ ...f, type: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {machineTypes.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="mach-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="mach-status"
              value={filters.status}
              onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="ACTIVE">Active</option>
              <option value="INACTIVE">Inactive</option>
            </select>
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : filteredMachines.length === 0 && allMachines.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No machines yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Add a machine to start tracking usage and maintenance.
          </p>
          {hasRole(['tenant_admin', 'accountant', 'operator']) ? (
            <button
              type="button"
              onClick={() => setShowModal(true)}
              className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add machine
            </button>
          ) : null}
        </div>
      ) : filteredMachines.length === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No machines match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try a different search or clear filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
          <DataTable data={filteredMachines} columns={cols} emptyMessage="" />
        </div>
      )}
      <Modal isOpen={showModal} onClose={handleCloseModal} title={editingMachine ? 'Edit machine' : 'Add machine'}>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
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
          <FormField label="Notes" className="md:col-span-2">
            <textarea
              value={form.notes || ''}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value || null }))}
              className="w-full px-3 py-2 border rounded"
              rows={3}
              placeholder="Optional notes"
            />
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-2">
            <button type="button" onClick={handleCloseModal} className="w-full sm:w-auto px-4 py-2 border rounded">Cancel</button>
            {editingMachine ? (
              <button
                type="button"
                onClick={handleUpdate}
                disabled={!form.name.trim() || !form.machine_type || !form.ownership_type || updateM.isPending}
                className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateM.isPending ? 'Updating...' : 'Update'}
              </button>
            ) : (
              <button
                type="button"
                onClick={handleCreate}
                disabled={!form.name.trim() || !form.machine_type || !form.ownership_type || createM.isPending}
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
