import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateWorkLog, useWorkers } from '../../hooks/useLabour';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';

export default function WorkLogFormPage() {
  const navigate = useNavigate();
  const createM = useCreateWorkLog();
  const { data: workers } = useWorkers({});
  const { data: cropCycles } = useCropCycles();
  const [crop_cycle_id, setCropCycleId] = useState('');
  const { data: projects } = useProjects(crop_cycle_id || undefined);
  const { formatMoney } = useFormatting();

  const [doc_no, setDocNo] = useState('');
  const [worker_id, setWorkerId] = useState('');
  const [work_date, setWorkDate] = useState(new Date().toISOString().split('T')[0]);
  const [project_id, setProjectId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [rate_basis, setRateBasis] = useState<'DAILY' | 'HOURLY' | 'PIECE'>('DAILY');
  const [units, setUnits] = useState('');
  const [rate, setRate] = useState('');
  const [notes, setNotes] = useState('');

  const u = parseFloat(units || '0');
  const r = parseFloat(rate || '0');
  const amount = u * r;

  const handleSubmit = async () => {
    if (!doc_no.trim() || !worker_id || !work_date || !crop_cycle_id || !project_id || !rate_basis || !(u > 0) || !(r >= 0)) return;
    const log = await createM.mutateAsync({
      doc_no: doc_no.trim(),
      worker_id,
      work_date,
      crop_cycle_id,
      project_id,
      activity_id: activity_id || undefined,
      rate_basis,
      units: u,
      rate: r,
      notes: notes || undefined,
    });
    navigate(`/app/labour/work-logs/${log.id}`);
  };

  return (
    <div>
      <PageHeader
        title="New Work Log"
        backTo="/app/labour/work-logs"
        breadcrumbs={[
          { label: 'Labour', to: '/app/labour' },
          { label: 'Work Logs', to: '/app/labour/work-logs' },
          { label: 'New' },
        ]}
      />
      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Doc No" required>
            <input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Worker" required>
            <select value={worker_id} onChange={(e) => setWorkerId(e.target.value)} className="w-full px-3 py-2 border rounded">
              <option value="">Select worker</option>
              {workers?.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
            </select>
          </FormField>
          <FormField label="Work Date" required>
            <input type="date" value={work_date} onChange={(e) => setWorkDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Crop Cycle" required>
            <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
              <option value="">Select crop cycle</option>
              {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Project" required>
            <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded" disabled={!crop_cycle_id}>
              <option value="">Select project</option>
              {(projects || [])?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </FormField>
          <FormField label="Activity (optional)">
            <input value={activity_id} onChange={(e) => setActivityId(e.target.value)} className="w-full px-3 py-2 border rounded" placeholder="UUID or leave blank" />
          </FormField>
          <FormField label="Rate basis" required>
            <select value={rate_basis} onChange={(e) => setRateBasis(e.target.value as 'DAILY' | 'HOURLY' | 'PIECE')} className="w-full px-3 py-2 border rounded">
              <option value="DAILY">DAILY</option>
              <option value="HOURLY">HOURLY</option>
              <option value="PIECE">PIECE</option>
            </select>
          </FormField>
          <FormField label="Units" required>
            <input type="number" step="any" min="0" value={units} onChange={(e) => setUnits(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Rate" required>
            <input type="number" step="any" min="0" value={rate} onChange={(e) => setRate(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Amount (computed)">{formatMoney(amount)}</FormField>
          <FormField label="Notes">
            <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} />
          </FormField>
        </div>
        <div className="flex gap-2 pt-4">
          <button type="button" onClick={() => navigate('/app/labour/work-logs')} className="px-4 py-2 border rounded">Cancel</button>
          <button onClick={handleSubmit} disabled={createM.isPending || !(u > 0) || !(r >= 0) || !doc_no.trim() || !worker_id || !crop_cycle_id || !project_id} className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">Create</button>
        </div>
      </div>
    </div>
  );
}
