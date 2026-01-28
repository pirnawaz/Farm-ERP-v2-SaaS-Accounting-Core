import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import { useWorkLog, useUpdateWorkLog, usePostWorkLog, useReverseWorkLog, useWorkers } from '../../hooks/useLabour';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useModules } from '../../contexts/ModulesContext';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateLabWorkLogPayload } from '../../types';

export default function WorkLogDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: log, isLoading } = useWorkLog(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/labour/work-logs';
  const updateM = useUpdateWorkLog();
  const postM = usePostWorkLog();
  const reverseM = useReverseWorkLog();
  const { data: workers } = useWorkers({});
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(log?.crop_cycle_id);
  const { isModuleEnabled } = useModules();
  const machineryEnabled = isModuleEnabled('machinery');
  const { data: machines } = useMachinesQuery(undefined);
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [worker_id, setWorkerId] = useState('');
  const [work_date, setWorkDate] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [rate_basis, setRateBasis] = useState<'DAILY' | 'HOURLY' | 'PIECE'>('DAILY');
  const [units, setUnits] = useState('');
  const [rate, setRate] = useState('');
  const [notes, setNotes] = useState('');

  useEffect(() => {
    if (log) {
      setDocNo(log.doc_no);
      setWorkerId(log.worker_id);
      setWorkDate(log.work_date);
      setCropCycleId(log.crop_cycle_id);
      setProjectId(log.project_id);
      setActivityId(log.activity_id || '');
      setMachineId(log.machine_id || '');
      setRateBasis(log.rate_basis as 'DAILY' | 'HOURLY' | 'PIECE');
      setUnits(String(log.units));
      setRate(String(log.rate));
      setNotes(log.notes || '');
      if (!showPostModal && !showReverseModal) setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [log, showPostModal, showReverseModal]);

  const isDraft = log?.status === 'DRAFT';
  const isPosted = log?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const u = parseFloat(units);
    const r = parseFloat(rate);
    if (isNaN(u) || u <= 0 || isNaN(r) || r < 0) return;
    const payload: UpdateLabWorkLogPayload = {
      doc_no,
      worker_id,
      work_date,
      crop_cycle_id,
      project_id,
      activity_id: activity_id || undefined,
      machine_id: machine_id || undefined,
      rate_basis,
      units: u,
      rate: r,
      notes: notes || undefined,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id || !reverseReason.trim()) return;
    await reverseM.mutateAsync({ id, payload: { posting_date: postingDate, reason: reverseReason } });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!log) return <div>Work log not found.</div>;

  return (
    <div>
      <PageHeader
        title={`Work Log ${log.doc_no}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Labour', to: '/app/labour' },
          { label: 'Work Logs', to: '/app/labour/work-logs' },
          { label: log.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{log.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Worker</dt><dd>{log.worker?.name || log.worker_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Work Date</dt><dd>{formatDate(log.work_date)}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop Cycle</dt><dd>{log.crop_cycle?.name || log.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Project</dt><dd>{log.project?.name || log.project_id}</dd></div>
          {log.machine && (
            <div><dt className="text-sm text-gray-500">Machine</dt><dd>{log.machine.code} - {log.machine.name}</dd></div>
          )}
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              log.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              log.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{log.status}</span></dd>
          </div>
          <div><dt className="text-sm text-gray-500">Units</dt><dd>{log.units}</dd></div>
          <div><dt className="text-sm text-gray-500">Rate</dt><dd><span className="tabular-nums">{formatMoney(log.rate)}</span></dd></div>
          <div><dt className="text-sm text-gray-500">Amount</dt><dd><span className="tabular-nums">{formatMoney(log.amount)}</span></dd></div>
          {log.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${log.posting_group_id}`} className="text-[#1F6F5C]">{log.posting_group_id}</Link></dd>
            </div>
          )}
          {log.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(log.posting_date)}</dd></div>}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Worker">
              <select value={worker_id} onChange={(e) => setWorkerId(e.target.value)} className="w-full px-3 py-2 border rounded">
                {workers?.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
              </select>
            </FormField>
            <FormField label="Work Date"><input type="date" value={work_date} onChange={(e) => setWorkDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Crop Cycle">
              <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
                {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select</option>
                {(projects || [])?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </FormField>
            {machineryEnabled && (
              <FormField label="Machine (optional)">
                <select
                  value={machine_id}
                  onChange={(e) => setMachineId(e.target.value)}
                  className="w-full px-3 py-2 border rounded"
                >
                  <option value="">Select machine (optional)</option>
                  {machines?.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.code} - {m.name}
                    </option>
                  ))}
                </select>
              </FormField>
            )}
            <FormField label="Rate basis">
              <select value={rate_basis} onChange={(e) => setRateBasis(e.target.value as 'DAILY' | 'HOURLY' | 'PIECE')} className="w-full px-3 py-2 border rounded">
                <option value="DAILY">DAILY</option>
                <option value="HOURLY">HOURLY</option>
                <option value="PIECE">PIECE</option>
              </select>
            </FormField>
            <FormField label="Units"><input type="number" step="any" min="0" value={units} onChange={(e) => setUnits(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Rate"><input type="number" step="any" min="0" value={rate} onChange={(e) => setRate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Notes"><input value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && <button onClick={() => setShowPostModal(true)} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>}
          </div>
        </div>
      ) : null}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Work Log">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Work Log">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Reason" required><textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowReverseModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleReverse} disabled={!reverseReason.trim() || reverseM.isPending} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
