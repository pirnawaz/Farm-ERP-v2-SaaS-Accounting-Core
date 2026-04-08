import { useMemo, useState, useCallback } from 'react';
import { useActivityTypes, useCreateActivityType, useUpdateActivityType } from '../../hooks/useCropOps';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import type { CropActivityType } from '../../types';

export default function ActivityTypesPage() {
  const [showModal, setShowModal] = useState(false);
  const [name, setName] = useState('');
  const [isActiveVal, setIsActiveVal] = useState(true);
  const [isActiveFilter, setIsActiveFilter] = useState<boolean | ''>('');

  const { data: types, isLoading } = useActivityTypes(
    isActiveFilter === '' ? undefined : { is_active: !!isActiveFilter },
  );
  const createM = useCreateActivityType();
  const updateM = useUpdateActivityType();

  const rows = types ?? [];
  const count = rows.length;

  const summaryLine = useMemo(() => {
    if (isActiveFilter === '') {
      return `${count} work ${count === 1 ? 'type' : 'types'}`;
    }
    const label = isActiveFilter ? 'active' : 'inactive';
    return `${count} ${label} work ${count === 1 ? 'type' : 'types'}`;
  }, [count, isActiveFilter]);

  const toggleActive = useCallback(
    (id: string, isActive: boolean) => {
      updateM.mutate({ id, payload: { is_active: !isActive } });
    },
    [updateM],
  );

  const cols: Column<CropActivityType>[] = [
    {
      header: 'Work type',
      accessor: (r) => <span className="font-medium text-gray-900">{r.name}</span>,
    },
    {
      header: 'Status',
      accessor: (r) => (
        <span
          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
            r.is_active ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-700'
          }`}
        >
          {r.is_active ? 'Active' : 'Inactive'}
        </span>
      ),
    },
    {
      header: 'Actions',
      accessor: (r) => (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            toggleActive(r.id, r.is_active);
          }}
          disabled={updateM.isPending}
          className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-50"
        >
          {r.is_active ? 'Deactivate' : 'Activate'}
        </button>
      ),
    },
  ];

  const handleCreate = async () => {
    if (!name.trim()) return;
    await createM.mutateAsync({ name: name.trim(), is_active: isActiveVal });
    setShowModal(false);
    setName('');
    setIsActiveVal(true);
  };

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-5xl">
      <PageHeader
        title="Work Types"
        description="Define the types of field work used in crop operations."
        helper="Use work types to organise and classify field activity. This is setup data for Field Work Logs — not operational history."
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Work Types' },
        ]}
        right={
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Add work type
          </button>
        }
      />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
          <div className="max-w-xs">
            <label htmlFor="wt-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="wt-status"
              value={String(isActiveFilter)}
              onChange={(e) => setIsActiveFilter(e.target.value === '' ? '' : e.target.value === 'true')}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All work types</option>
              <option value="true">Active only</option>
              <option value="false">Inactive only</option>
            </select>
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {count === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">
            {isActiveFilter === '' ? 'No work types yet.' : 'No work types match your filters.'}
          </h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            {isActiveFilter === ''
              ? 'Add one to organise crop operations.'
              : 'Try showing all work types or adjust the status filter.'}
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            {isActiveFilter !== '' ? (
              <button
                type="button"
                onClick={() => setIsActiveFilter('')}
                className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
              >
                Show all work types
              </button>
            ) : null}
            <button
              type="button"
              onClick={() => setShowModal(true)}
              className="inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
            >
              Add work type
            </button>
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={rows} columns={cols} emptyMessage="" />
        </div>
      )}

      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="Add work type">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <p className="text-sm text-gray-500 md:col-span-2">Examples: ploughing, sowing, spraying, fertiliser.</p>
          <FormField label="Name" required>
            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Active">
            <label>
              <input type="checkbox" checked={isActiveVal} onChange={(e) => setIsActiveVal(e.target.checked)} /> Active
            </label>
          </FormField>
          <div className="md:col-span-2 flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2 border-t border-gray-100">
            <button type="button" onClick={() => setShowModal(false)} className="w-full sm:w-auto px-4 py-2 border rounded">
              Cancel
            </button>
            <button
              type="button"
              onClick={handleCreate}
              disabled={!name.trim() || createM.isPending}
              className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              Create
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
