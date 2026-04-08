import { useState } from 'react';
import { useWorkers, useCreateWorker } from '../../hooks/useLabour';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { FilterBar, FilterField, FilterGrid } from '../../components/FilterBar';
import type { LabWorker } from '../../types';
import { Badge } from '../../components/Badge';

export default function WorkersPage() {
  const [isActive, setIsActive] = useState<boolean | ''>('');
  const [workerType, setWorkerType] = useState('');
  const [q, setQ] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [name, setName] = useState('');
  const [workerNo, setWorkerNo] = useState('');
  const [workerTypeVal, setWorkerTypeVal] = useState<'HARI' | 'STAFF' | 'CONTRACT'>('HARI');
  const [rateBasis, setRateBasis] = useState<'DAILY' | 'HOURLY' | 'PIECE'>('DAILY');
  const [defaultRate, setDefaultRate] = useState('');
  const [phone, setPhone] = useState('');
  const [isActiveVal, setIsActiveVal] = useState(true);
  const [createParty, setCreateParty] = useState(true);

  const { data: workers, isLoading } = useWorkers({
    is_active: isActive === '' ? undefined : !!isActive,
    worker_type: workerType || undefined,
    q: q || undefined,
  });
  const createM = useCreateWorker();

  const hasFilters = isActive !== '' || !!workerType || !!q.trim();
  const workerCount = (workers ?? []).length;

  const clearFilters = () => {
    setIsActive('');
    setWorkerType('');
    setQ('');
  };

  const cols: Column<LabWorker>[] = [
    {
      header: 'Worker',
      accessor: (r) => (
        <div className="min-w-[10rem]">
          <div className="font-medium text-gray-900">{r.name}</div>
          {r.phone ? <div className="text-xs text-gray-500">{r.phone}</div> : null}
        </div>
      ),
    },
    {
      header: 'Identifier',
      accessor: (r) => <span className="tabular-nums text-gray-700">{r.worker_no || '—'}</span>,
    },
    {
      header: 'Type',
      accessor: (r) => <span className="text-gray-700">{r.worker_type}</span>,
    },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Badge>
      ),
    },
  ];

  const handleCreate = async () => {
    if (!name.trim()) return;
    await createM.mutateAsync({
      name: name.trim(),
      worker_no: workerNo || undefined,
      worker_type: workerTypeVal,
      rate_basis: rateBasis,
      default_rate: defaultRate ? parseFloat(defaultRate) : undefined,
      phone: phone || undefined,
      is_active: isActiveVal,
      create_party: createParty,
    });
    setShowModal(false);
    setName('');
    setWorkerNo('');
    setWorkerTypeVal('HARI');
    setRateBasis('DAILY');
    setDefaultRate('');
    setPhone('');
    setIsActiveVal(true);
    setCreateParty(true);
  };

  const summaryLine = `${workerCount} ${workerCount === 1 ? 'worker' : 'workers'}${hasFilters ? ' (filtered)' : ''}`;

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Workers"
        tooltip="View and manage your workers."
        description="View and manage your workers."
        helper="Use this page to keep track of the people doing labour work."
        backTo="/app/labour"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Labour Overview', to: '/app/labour' },
          { label: 'Workers' },
        ]}
        right={
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Add worker
          </button>
        }
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
        <FilterBar>
          <FilterGrid className="lg:grid-cols-3 xl:grid-cols-3">
            <FilterField label="Search">
              <input
                type="text"
                placeholder="Search by name"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </FilterField>
            <FilterField label="Status">
              <select
                value={String(isActive)}
                onChange={(e) => setIsActive(e.target.value === '' ? '' : e.target.value === 'true')}
              >
                <option value="">All</option>
                <option value="true">Active</option>
                <option value="false">Inactive</option>
              </select>
            </FilterField>
            <FilterField label="Type">
              <select value={workerType} onChange={(e) => setWorkerType(e.target.value)}>
                <option value="">All types</option>
                <option value="HARI">Hari</option>
                <option value="STAFF">Staff</option>
                <option value="CONTRACT">Contract</option>
              </select>
            </FilterField>
          </FilterGrid>
        </FilterBar>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      ) : (workers?.length ?? 0) === 0 && !hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No workers yet.</h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">Add a worker to start recording labour activity.</p>
          <button
            type="button"
            onClick={() => setShowModal(true)}
            className="mt-6 inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
          >
            Add worker
          </button>
        </div>
      ) : (workers?.length ?? 0) === 0 && hasFilters ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">No workers match your filters.</h3>
          <p className="mt-2 text-sm text-gray-600">Try adjusting search or status, or clear filters.</p>
          <button
            type="button"
            onClick={clearFilters}
            className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
          >
            Clear filters
          </button>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable data={workers || []} columns={cols} emptyMessage="" />
        </div>
      )}
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="Add worker">
        <div className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField label="Name" required>
              <input value={name} onChange={(e) => setName(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Worker No">
              <input
                value={workerNo}
                onChange={(e) => setWorkerNo(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                placeholder="Leave blank to auto-generate"
              />
            </FormField>
            <FormField label="Type">
              <select
                value={workerTypeVal}
                onChange={(e) => setWorkerTypeVal(e.target.value as 'HARI' | 'STAFF' | 'CONTRACT')}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="HARI">HARI</option>
                <option value="STAFF">STAFF</option>
                <option value="CONTRACT">CONTRACT</option>
              </select>
            </FormField>
            <FormField label="Rate basis">
              <select
                value={rateBasis}
                onChange={(e) => setRateBasis(e.target.value as 'DAILY' | 'HOURLY' | 'PIECE')}
                className="w-full px-3 py-2 border rounded"
              >
                <option value="DAILY">DAILY</option>
                <option value="HOURLY">HOURLY</option>
                <option value="PIECE">PIECE</option>
              </select>
            </FormField>
            <FormField label="Default rate">
              <input
                type="number"
                step="any"
                min="0"
                value={defaultRate}
                onChange={(e) => setDefaultRate(e.target.value)}
                className="w-full px-3 py-2 border rounded"
              />
            </FormField>
            <FormField label="Phone">
              <input value={phone} onChange={(e) => setPhone(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Active" className="md:col-span-2">
              <label>
                <input type="checkbox" checked={isActiveVal} onChange={(e) => setIsActiveVal(e.target.checked)} /> Active
              </label>
            </FormField>
            <FormField label="Create as Party for payments" className="md:col-span-2">
              <label>
                <input type="checkbox" checked={createParty} onChange={(e) => setCreateParty(e.target.checked)} /> Create Party (for wage payments)
              </label>
            </FormField>
          </div>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
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
