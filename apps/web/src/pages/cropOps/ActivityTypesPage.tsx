import { useState } from 'react';
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
    isActiveFilter === '' ? undefined : { is_active: !!isActiveFilter }
  );
  const createM = useCreateActivityType();
  const updateM = useUpdateActivityType();

  const cols: Column<CropActivityType>[] = [
    { header: 'Name', accessor: 'name' },
    {
      header: 'Active',
      accessor: (r) => (
        <button
          type="button"
          onClick={() => updateM.mutate({ id: r.id, payload: { is_active: !r.is_active } })}
          disabled={updateM.isPending}
          className={`px-2 py-1 rounded text-xs ${r.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}
        >
          {r.is_active ? 'Yes' : 'No'}
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

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Crop Ops → Activity Types"
        backTo="/app/crop-ops"
        breadcrumbs={[{ label: 'Crop Ops', to: '/app/crop-ops' }, { label: 'Activity Types' }]}
        right={
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">
            New Type
          </button>
        }
      />
      <p className="text-sm text-gray-500 mb-4">Activity types represent operations like ploughing, sowing, spraying.</p>
      <div className="flex gap-4 mb-4 flex-wrap">
        <select
          value={String(isActiveFilter)}
          onChange={(e) => setIsActiveFilter(e.target.value === '' ? '' : e.target.value === 'true')}
          className="px-3 py-2 border rounded text-sm"
        >
          <option value="">All</option>
          <option value="true">Active</option>
          <option value="false">Inactive</option>
        </select>
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable data={types || []} columns={cols} emptyMessage="No activity types. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New Activity Type">
        <div className="space-y-4">
          <p className="text-sm text-gray-500">e.g. Ploughing, Sowing, Fertilizer, Spraying…</p>
          <FormField label="Name" required>
            <input value={name} onChange={(e) => setName(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Active">
            <label><input type="checkbox" checked={isActiveVal} onChange={(e) => setIsActiveVal(e.target.checked)} /> Active</label>
          </FormField>
          <div className="flex gap-2 pt-4">
            <button type="button" onClick={() => setShowModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleCreate} disabled={!name.trim() || createM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50">
              Create
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
