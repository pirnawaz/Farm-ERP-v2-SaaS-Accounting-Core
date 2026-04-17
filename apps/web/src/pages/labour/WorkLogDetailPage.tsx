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
import { Term } from '../../components/Term';
import { term } from '../../config/terminology';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

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
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0]);
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
      if (!showPostModal && !showReverseModal) {
        const today = new Date().toISOString().split('T')[0];
        setPostingDate(today);
        setReversePostingDate(today);
      }
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
    if (!id || !postingDate) return;
    try {
      await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
      setShowPostModal(false);
      postM.reset();
    } catch {
      /* shown in modal */
    }
  };

  const canConfirmLabourReverse = Boolean(id && reversePostingDate && reverseReason.trim());

  const handleReverse = async () => {
    if (!canConfirmLabourReverse) return;
    try {
      await reverseM.mutateAsync({ id: id!, payload: { posting_date: reversePostingDate, reason: reverseReason } });
      setShowReverseModal(false);
      setReverseReason('');
      reverseM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!log) return <div>Work log not found.</div>;

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Work entry ${log.doc_no}`}
        description="Labour time and pay for a worker against crop and field cycles."
        helper="People and payables live here—field work without labour cost is under Crop Ops field work logs."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Labour Overview', to: '/app/labour' },
          { label: 'Work entries', to: '/app/labour/work-logs' },
          { label: log.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{log.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Worker</dt><dd>{log.worker?.name || log.worker_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Work date</dt><dd className="tabular-nums">{formatDate(log.work_date, { variant: 'medium' })}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop cycle</dt><dd>{log.crop_cycle?.name || log.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">{term('fieldCycle')}</dt><dd>{log.project?.name || log.project_id}</dd></div>
          {log.machine && (
            <div><dt className="text-sm text-gray-500">Machine</dt><dd>{log.machine.code} - {log.machine.name}</dd></div>
          )}
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><PostingStatusBadge status={log.status} /></dd>
          </div>
          <div><dt className="text-sm text-gray-500">Units</dt><dd>{log.units}</dd></div>
          <div><dt className="text-sm text-gray-500">Rate</dt><dd><span className="tabular-nums">{formatMoney(log.rate)}</span></dd></div>
          <div><dt className="text-sm text-gray-500">Amount</dt><dd><span className="tabular-nums">{formatMoney(log.amount)}</span></dd></div>
          {log.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500"><Term k="postingGroup" showHint /></dt>
              <dd><Link to={`/app/posting-groups/${log.posting_group_id}`} className="text-[#1F6F5C]">{log.posting_group_id}</Link></dd>
            </div>
          )}
          {log.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(log.posting_date)}</dd></div>}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6">
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
            <FormField label={term('fieldCycle')}>
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
          <div className="flex flex-col-reverse sm:flex-row sm:flex-wrap gap-2">
            <button type="button" onClick={handleSave} disabled={updateM.isPending} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && (
              <button
                type="button"
                onClick={() => {
                  postM.reset();
                  setShowPostModal(true);
                }}
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded min-h-[44px]"
              >
                Record to accounts
              </button>
            )}
          </div>
        </div>
      ) : null}

      {isPosted && canPost && (
        <div>
          <button
            type="button"
            onClick={() => {
              reverseM.reset();
              setShowReverseModal(true);
            }}
            className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded min-h-[44px]"
          >
            {term('reverseAction')}
          </button>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          postM.reset();
        }}
        title="Record work entry to accounts"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This will record wages payable and related labour amounts in the accounts for the posting date below.
          </p>
          <PrePostChecklist
            items={[{ ok: Boolean(postingDate), label: 'Posting date chosen' }]}
            blockingHint={!postingDate ? 'Choose a posting date before recording.' : undefined}
          />
          <OperatorErrorCallout error={postM.isError ? formatOperatorError(postM.error) : null} />
          <FormField label="Posting date" required>
            <input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded min-h-[44px]" />
          </FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowPostModal(false);
                postM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending || !postingDate}
              className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {postM.isPending ? term('postActionPending') : 'Confirm'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setReverseReason('');
          reverseM.reset();
        }}
        title={term('reverseAction')}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This creates offsetting entries as of the posting date below. Cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(reversePostingDate), label: 'Posting date chosen' },
              { ok: Boolean(reverseReason.trim()), label: 'Reason entered' },
            ]}
            blockingHint={!canConfirmLabourReverse ? 'Choose a posting date and enter a reason before reversing.' : undefined}
          />
          <OperatorErrorCallout error={reverseM.isError ? formatOperatorError(reverseM.error) : null} />
          <FormField label="Posting date" required>
            <input
              type="date"
              value={reversePostingDate}
              onChange={(e) => setReversePostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <FormField label="Reason" required>
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={2}
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowReverseModal(false);
                setReverseReason('');
                reverseM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={!canConfirmLabourReverse || reverseM.isPending}
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {reverseM.isPending ? term('reverseActionPending') : 'Confirm reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
