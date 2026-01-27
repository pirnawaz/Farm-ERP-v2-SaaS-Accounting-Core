import { useState } from 'react';
import { useWorkers, useCreateWorker } from '../../hooks/useLabour';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import type { LabWorker } from '../../types';

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

  const cols: Column<LabWorker>[] = [
    { header: 'Name', accessor: 'name' },
    { header: 'Worker No', accessor: (r) => r.worker_no || '—' },
    { header: 'Type', accessor: 'worker_type' },
    { header: 'Rate basis', accessor: 'rate_basis' },
    { header: 'Default rate', accessor: (r) => (r.default_rate != null ? String(r.default_rate) : '—') },
    { header: 'Active', accessor: (r) => (r.is_active ? 'Yes' : 'No') },
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

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Workers"
        backTo="/app/labour"
        breadcrumbs={[{ label: 'Labour', to: '/app/labour' }, { label: 'Workers' }]}
        right={
          <button onClick={() => setShowModal(true)} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">New Worker</button>
        }
      />
      <div className="flex gap-4 mb-4 flex-wrap">
        <select value={String(isActive)} onChange={(e) => setIsActive(e.target.value === '' ? '' : e.target.value === 'true')} className="px-3 py-2 border rounded text-sm">
          <option value="">All active</option>
          <option value="true">Active</option>
          <option value="false">Inactive</option>
        </select>
        <select value={workerType} onChange={(e) => setWorkerType(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All types</option>
          <option value="HARI">HARI</option>
          <option value="STAFF">STAFF</option>
          <option value="CONTRACT">CONTRACT</option>
        </select>
        <input type="text" placeholder="Search name" value={q} onChange={(e) => setQ(e.target.value)} className="px-3 py-2 border rounded text-sm w-48" />
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable data={workers || []} columns={cols} emptyMessage="No workers. Create one." />
      </div>
      <Modal isOpen={showModal} onClose={() => setShowModal(false)} title="New Worker">
        <div className="space-y-4">
          <FormField label="Name" required><input value={name} onChange={(e) => setName(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Worker No"><input value={workerNo} onChange={(e) => setWorkerNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Type">
            <select value={workerTypeVal} onChange={(e) => setWorkerTypeVal(e.target.value as 'HARI' | 'STAFF' | 'CONTRACT')} className="w-full px-3 py-2 border rounded">
              <option value="HARI">HARI</option>
              <option value="STAFF">STAFF</option>
              <option value="CONTRACT">CONTRACT</option>
            </select>
          </FormField>
          <FormField label="Rate basis">
            <select value={rateBasis} onChange={(e) => setRateBasis(e.target.value as 'DAILY' | 'HOURLY' | 'PIECE')} className="w-full px-3 py-2 border rounded">
              <option value="DAILY">DAILY</option>
              <option value="HOURLY">HOURLY</option>
              <option value="PIECE">PIECE</option>
            </select>
          </FormField>
          <FormField label="Default rate"><input type="number" step="any" min="0" value={defaultRate} onChange={(e) => setDefaultRate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Phone"><input value={phone} onChange={(e) => setPhone(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Active"><label><input type="checkbox" checked={isActiveVal} onChange={(e) => setIsActiveVal(e.target.checked)} /> Active</label></FormField>
          <FormField label="Create as Party for payments"><label><input type="checkbox" checked={createParty} onChange={(e) => setCreateParty(e.target.checked)} /> Create Party (for wage payments)</label></FormField>
          <div className="flex gap-2 pt-4">
            <button type="button" onClick={() => setShowModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleCreate} disabled={!name.trim() || createM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50">Create</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
