import { useEffect, useState } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useFieldJob,
  useUpdateFieldJob,
  useAddFieldJobInput,
  useUpdateFieldJobInput,
  useDeleteFieldJobInput,
  useAddFieldJobLabour,
  useUpdateFieldJobLabour,
  useDeleteFieldJobLabour,
  useAddFieldJobMachine,
  useUpdateFieldJobMachine,
  useDeleteFieldJobMachine,
  usePostFieldJob,
  useReverseFieldJob,
} from '../../../hooks/useFieldJobs';
import { useProjects } from '../../../hooks/useProjects';
import { useProductionUnits } from '../../../hooks/useProductionUnits';
import { useLandParcels } from '../../../hooks/useLandParcels';
import { useActivityTypes } from '../../../hooks/useCropOps';
import { useWorkers } from '../../../hooks/useLabour';
import { useInventoryStores, useInventoryItems } from '../../../hooks/useInventory';
import { useMachinesQuery } from '../../../hooks/useMachinery';
import { LoadingSpinner } from '../../../components/LoadingSpinner';
import { Modal } from '../../../components/Modal';
import { FormField } from '../../../components/FormField';
import { PageHeader } from '../../../components/PageHeader';
import { useRole } from '../../../hooks/useRole';
import { useFormatting } from '../../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import { PostingStatusBadge } from '../../../utils/postingStatusDisplay';
import { formatItemDisplayName } from '../../../utils/formatItemDisplay';
import { Term } from '../../../components/Term';
import { term } from '../../../config/terminology';
import { FieldJobCostSummary } from '../../../components/fieldJobs/FieldJobCostSummary';
import { TraceabilityPanel } from '../../../components/traceability/TraceabilityPanel';
import { PrimaryWorkflowBanner } from '../../../components/workflow/PrimaryWorkflowBanner';
import { DuplicateWorkflowRiskCallout } from '../../../components/workflow/DuplicateWorkflowRiskCallout';
import type {
  FieldJobInputLine,
  FieldJobLabourLine,
  FieldJobMachineLine,
  UpdateFieldJobPayload,
} from '../../../types';

export default function FieldJobDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/crop-ops/field-jobs';

  const { data: job, isLoading } = useFieldJob(id || '');
  const updateM = useUpdateFieldJob();
  const addInputM = useAddFieldJobInput();
  const updateInputM = useUpdateFieldJobInput();
  const deleteInputM = useDeleteFieldJobInput();
  const addLabourM = useAddFieldJobLabour();
  const updateLabourM = useUpdateFieldJobLabour();
  const deleteLabourM = useDeleteFieldJobLabour();
  const addMachineM = useAddFieldJobMachine();
  const updateMachineM = useUpdateFieldJobMachine();
  const deleteMachineM = useDeleteFieldJobMachine();
  const postM = usePostFieldJob();
  const reverseM = useReverseFieldJob();

  const { data: projectsForCycle } = useProjects(job?.crop_cycle_id || undefined);
  const { data: productionUnits } = useProductionUnits();
  const { data: landParcels } = useLandParcels();
  const { data: activityTypes } = useActivityTypes({ is_active: true });
  const { data: workers } = useWorkers();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: machines } = useMachinesQuery();

  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [doc_no, setDocNo] = useState('');
  const [job_date, setJobDate] = useState('');
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [crop_activity_type_id, setCropActivityTypeId] = useState('');
  const [notes, setNotes] = useState('');

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(() => uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [addIn, setAddIn] = useState({ store_id: '', item_id: '', qty: '' });
  const [addLab, setAddLab] = useState({
    worker_id: '',
    rate_basis: 'DAILY',
    units: '',
    rate: '',
    amount: '',
  });
  const [addMach, setAddMach] = useState({
    machine_id: '',
    usage_qty: '',
    meter_unit_snapshot: '',
    rate_snapshot: '',
    amount: '',
  });

  const [editInput, setEditInput] = useState<FieldJobInputLine | null>(null);
  const [editLabour, setEditLabour] = useState<FieldJobLabourLine | null>(null);
  const [editMachine, setEditMachine] = useState<FieldJobMachineLine | null>(null);

  useEffect(() => {
    if (!job) return;
    setDocNo(job.doc_no || '');
    setJobDate(String(job.job_date).slice(0, 10));
    setProjectId(job.project_id);
    setProductionUnitId(job.production_unit_id || '');
    setLandParcelId(job.land_parcel_id || '');
    setCropActivityTypeId(job.crop_activity_type_id || '');
    setNotes(job.notes || '');
    if (!showPostModal && !showReverseModal) {
      setPostingDate(new Date().toISOString().split('T')[0]);
      setReversePostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [job, showPostModal, showReverseModal]);

  const isDraft = job?.status === 'DRAFT';
  const isPosted = job?.status === 'POSTED';
  const isReversed = job?.status === 'REVERSED';
  const readOnly = !isDraft;
  const canEdit = isDraft && hasRole(['tenant_admin', 'accountant', 'operator']);
  const canPostReverse = hasRole(['tenant_admin', 'accountant']);

  const saveHeader = async () => {
    if (!id || !canEdit) return;
    const payload: UpdateFieldJobPayload = {
      doc_no: doc_no.trim() || undefined,
      job_date,
      project_id,
      production_unit_id: production_unit_id || undefined,
      land_parcel_id: land_parcel_id || undefined,
      crop_activity_type_id: crop_activity_type_id || undefined,
      notes: notes.trim() || undefined,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({
      id,
      payload: { posting_date: postingDate, idempotency_key: idempotencyKey },
    });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id) return;
    await reverseM.mutateAsync({
      id,
      payload: { posting_date: reversePostingDate, reason: reverseReason || undefined },
    });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }
  if (!job) return <div className="text-gray-600">Field job not found.</div>;

  const title = job.doc_no?.trim() || `Field job ${job.id.slice(0, 8)}`;

  return (
    <div className="space-y-6 max-w-5xl">
      <PageHeader
        title={title}
        description="One operational document: header, inputs, labour, and machinery. When you post, stock, labour, and machinery usage and costs are recorded—nothing is posted until you confirm."
        helper="This job is meant to cover labour, machinery, and inputs together—do not post the same work again as separate machinery usage or labour logs."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Field jobs', to: '/app/crop-ops/field-jobs' },
          { label: title },
        ]}
        right={
          <div className="flex flex-wrap items-center gap-2">
            <PostingStatusBadge status={job.status} />
            {isDraft && canPostReverse ? (
              <button
                type="button"
                onClick={() => setShowPostModal(true)}
                className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a]"
              >
                {term('postAction')}
              </button>
            ) : null}
            {isPosted && canPostReverse ? (
              <button
                type="button"
                onClick={() => setShowReverseModal(true)}
                className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100"
              >
                Reverse
              </button>
            ) : null}
          </div>
        }
      />

      <PrimaryWorkflowBanner variant="field-job" />

      <TraceabilityPanel traceability={job.traceability} />

      <DuplicateWorkflowRiskCallout context="field-job" traceability={job.traceability} />

      {readOnly ? (
        <div
          className={`rounded-lg border p-4 text-sm ${
            isPosted
              ? 'border-amber-200 bg-amber-50 text-amber-950'
              : 'border-gray-200 bg-gray-50 text-gray-800'
          }`}
          role="status"
        >
          {isPosted ? (
            <p>
              <strong>Posted — read-only.</strong> This job is locked. Use <strong>Reverse</strong> if you need to
              correct it (admin or accountant).
            </p>
          ) : (
            <p>
              <strong>Reversed — read-only.</strong> This document is closed for editing.
            </p>
          )}
        </div>
      ) : null}

      <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
        <h2 className="text-sm font-semibold text-gray-900 uppercase tracking-wide">Job header</h2>
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div>
            <dt className="text-gray-500">Crop cycle</dt>
            <dd className="font-medium text-gray-900">{job.crop_cycle?.name || job.crop_cycle_id}</dd>
          </div>
          <div>
            <dt className="text-gray-500">Status</dt>
            <dd>
              <PostingStatusBadge status={job.status} />
            </dd>
          </div>
          {job.posting_group_id ? (
            <div className="md:col-span-2">
              <dt className="text-gray-500">
                <Term k="postingGroup" showHint />
              </dt>
              <dd>
                <Link to={`/app/posting-groups/${job.posting_group_id}`} className="text-[#1F6F5C]">
                  {job.posting_group_id}
                </Link>
              </dd>
            </div>
          ) : null}
          {job.posting_date ? (
            <div>
              <dt className="text-gray-500">Posting date</dt>
              <dd className="tabular-nums">{formatDate(job.posting_date, { variant: 'medium' })}</dd>
            </div>
          ) : null}
        </dl>

        {canEdit ? (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-gray-100">
            <FormField label="Document reference">
              <input
                id="fj-d-doc"
                value={doc_no}
                onChange={(e) => setDocNo(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              />
            </FormField>
            <FormField label="Job date" required>
              <input
                id="fj-d-date"
                type="date"
                value={job_date}
                onChange={(e) => setJobDate(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              />
            </FormField>
            <FormField label="Project" required>
              <select
                id="fj-d-project"
                value={project_id}
                onChange={(e) => setProjectId(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                {(projectsForCycle ?? []).map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Production unit">
              <select
                id="fj-d-pu"
                value={production_unit_id}
                onChange={(e) => setProductionUnitId(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="">—</option>
                {(productionUnits ?? []).map((u) => (
                  <option key={u.id} value={u.id}>
                    {u.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Land parcel">
              <select
                id="fj-d-lp"
                value={land_parcel_id}
                onChange={(e) => setLandParcelId(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="">—</option>
                {(landParcels ?? []).map((lp) => (
                  <option key={lp.id} value={lp.id}>
                    {lp.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Work type">
              <select
                id="fj-d-wt"
                value={crop_activity_type_id}
                onChange={(e) => setCropActivityTypeId(e.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="">—</option>
                {(activityTypes ?? []).map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name}
                  </option>
                ))}
              </select>
            </FormField>
            <div className="md:col-span-2">
              <FormField label="Notes">
                <textarea
                  id="fj-d-notes"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  rows={3}
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                />
              </FormField>
            </div>
            <div className="md:col-span-2">
              <button
                type="button"
                onClick={() => saveHeader()}
                disabled={updateM.isPending}
                className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white hover:bg-[#1a5a4a] disabled:opacity-50"
              >
                {updateM.isPending ? 'Saving…' : 'Save header'}
              </button>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-2 text-sm border-t border-gray-100">
            <div>
              <span className="text-gray-500">Job date </span>
              <span className="tabular-nums font-medium">{formatDate(job.job_date, { variant: 'medium' })}</span>
            </div>
            <div>
              <span className="text-gray-500">Project </span>
              <span className="font-medium">{job.project?.name}</span>
            </div>
            <div>
              <span className="text-gray-500">Production unit </span>
              <span>{job.production_unit?.name || '—'}</span>
            </div>
            <div>
              <span className="text-gray-500">Land parcel </span>
              <span>{job.land_parcel?.name || '—'}</span>
            </div>
            <div className="md:col-span-2">
              <span className="text-gray-500">Notes </span>
              <span className="whitespace-pre-wrap">{job.notes?.trim() || '—'}</span>
            </div>
          </div>
        )}
      </section>

      <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-sm font-semibold text-gray-900 mb-3">Inputs used</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Store</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Item</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Qty</th>
                {(isPosted || isReversed) && (
                  <>
                    <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Unit cost</th>
                    <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Line total</th>
                  </>
                )}
                {canEdit ? <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Actions</th> : null}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {(job.inputs ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-3 py-2">{line.store?.name || line.store_id}</td>
                  <td className="px-3 py-2">{formatItemDisplayName(line.item)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{line.qty}</td>
                  {(isPosted || isReversed) && (
                    <>
                      <td className="px-3 py-2 text-right tabular-nums">
                        {line.unit_cost_snapshot != null ? formatMoney(line.unit_cost_snapshot) : '—'}
                      </td>
                      <td className="px-3 py-2 text-right tabular-nums">
                        {line.line_total != null ? formatMoney(line.line_total) : '—'}
                      </td>
                    </>
                  )}
                  {canEdit ? (
                    <td className="px-3 py-2 text-right space-x-2">
                      <button
                        type="button"
                        className="text-[#1F6F5C] text-xs font-medium"
                        onClick={() => setEditInput(line)}
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        className="text-red-600 text-xs"
                        onClick={() => {
                          if (window.confirm('Remove this input line?')) {
                            deleteInputM.mutate({ id: job.id, lineId: line.id });
                          }
                        }}
                      >
                        Remove
                      </button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {canEdit ? (
          <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
            <FormField label="Store">
              <select
                id="fj-in-store"
                value={addIn.store_id}
                onChange={(e) => setAddIn((s) => ({ ...s, store_id: e.target.value, item_id: '' }))}
                className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm min-w-[140px]"
              >
                <option value="">—</option>
                {(stores ?? []).map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Item">
              <select
                id="fj-in-item"
                value={addIn.item_id}
                onChange={(e) => setAddIn((s) => ({ ...s, item_id: e.target.value }))}
                className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm min-w-[160px]"
              >
                <option value="">—</option>
                {(items ?? []).map((it) => (
                  <option key={it.id} value={it.id}>
                    {it.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Qty">
              <input
                id="fj-in-qty"
                type="number"
                step="any"
                min="0"
                value={addIn.qty}
                onChange={(e) => setAddIn((s) => ({ ...s, qty: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm tabular-nums"
              />
            </FormField>
            <button
              type="button"
              disabled={
                addInputM.isPending ||
                !addIn.store_id ||
                !addIn.item_id ||
                !addIn.qty ||
                parseFloat(addIn.qty) <= 0
              }
              onClick={async () => {
                await addInputM.mutateAsync({
                  id: job.id,
                  payload: {
                    store_id: addIn.store_id,
                    item_id: addIn.item_id,
                    qty: parseFloat(addIn.qty),
                  },
                });
                setAddIn({ store_id: '', item_id: '', qty: '' });
              }}
              className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-40"
            >
              Add input line
            </button>
          </div>
        ) : null}
      </section>

      <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-sm font-semibold text-gray-900 mb-3">Labour used</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Worker</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Basis</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Units</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Rate</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Amount</th>
                {canEdit ? <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Actions</th> : null}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {(job.labour ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-3 py-2">{line.worker?.name || line.worker_id}</td>
                  <td className="px-3 py-2">{line.rate_basis || '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{line.units}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{line.rate}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {line.amount != null ? formatMoney(line.amount) : '—'}
                  </td>
                  {canEdit ? (
                    <td className="px-3 py-2 text-right space-x-2">
                      <button
                        type="button"
                        className="text-[#1F6F5C] text-xs font-medium"
                        onClick={() => setEditLabour(line)}
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        className="text-red-600 text-xs"
                        onClick={() => {
                          if (window.confirm('Remove this labour line?')) {
                            deleteLabourM.mutate({ id: job.id, lineId: line.id });
                          }
                        }}
                      >
                        Remove
                      </button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {canEdit ? (
          <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
            <FormField label="Worker">
              <select
                id="fj-lab-w"
                value={addLab.worker_id}
                onChange={(e) => setAddLab((s) => ({ ...s, worker_id: e.target.value }))}
                className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm min-w-[160px]"
              >
                <option value="">—</option>
                {(workers ?? []).map((w) => (
                  <option key={w.id} value={w.id}>
                    {w.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Basis">
              <select
                id="fj-lab-b"
                value={addLab.rate_basis}
                onChange={(e) => setAddLab((s) => ({ ...s, rate_basis: e.target.value }))}
                className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              >
                <option value="DAILY">DAILY</option>
                <option value="HOURLY">HOURLY</option>
                <option value="PIECE">PIECE</option>
              </select>
            </FormField>
            <FormField label="Units">
              <input
                id="fj-lab-u"
                type="number"
                step="any"
                min="0"
                value={addLab.units}
                onChange={(e) => setAddLab((s) => ({ ...s, units: e.target.value }))}
                className="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              />
            </FormField>
            <FormField label="Rate">
              <input
                id="fj-lab-r"
                type="number"
                step="any"
                min="0"
                value={addLab.rate}
                onChange={(e) => setAddLab((s) => ({ ...s, rate: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              />
            </FormField>
            <FormField label="Amount (optional)">
              <input
                id="fj-lab-a"
                type="number"
                step="any"
                min="0"
                value={addLab.amount}
                onChange={(e) => setAddLab((s) => ({ ...s, amount: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                placeholder="Auto"
              />
            </FormField>
            <button
              type="button"
              disabled={
                addLabourM.isPending ||
                !addLab.worker_id ||
                !addLab.units ||
                parseFloat(addLab.units) <= 0 ||
                !addLab.rate ||
                parseFloat(addLab.rate) < 0
              }
              onClick={async () => {
                await addLabourM.mutateAsync({
                  id: job.id,
                  payload: {
                    worker_id: addLab.worker_id,
                    rate_basis: addLab.rate_basis || undefined,
                    units: parseFloat(addLab.units),
                    rate: parseFloat(addLab.rate),
                    amount: addLab.amount ? parseFloat(addLab.amount) : undefined,
                  },
                });
                setAddLab({ worker_id: '', rate_basis: 'DAILY', units: '', rate: '', amount: '' });
              }}
              className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-40"
            >
              Add labour line
            </button>
          </div>
        ) : null}
      </section>

      <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-sm font-semibold text-gray-900">Machines used</h2>
        <p className="mt-1 text-sm text-gray-600 mb-3">
          Usage quantity is always stored. After posting, the rate snapshot and machinery cost come from the active machinery
          rate card (or from optional manual values you entered on the line while in draft).
        </p>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 text-sm">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Machine</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Usage qty</th>
                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500">Meter unit</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Rate snapshot</th>
                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Machinery cost</th>
                {canEdit ? <th className="px-3 py-2 text-right text-xs font-medium text-gray-500">Actions</th> : null}
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {(job.machines ?? []).map((line) => (
                <tr key={line.id}>
                  <td className="px-3 py-2">
                    <div className="font-medium text-gray-900">{line.machine?.name || line.machine_id}</div>
                    {(isPosted || isReversed) && line.pricing_basis ? (
                      <div className="text-xs text-gray-500 mt-0.5">
                        {line.pricing_basis === 'RATE_CARD'
                          ? 'Rate card'
                          : line.pricing_basis === 'MANUAL'
                            ? 'Manual amount'
                            : line.pricing_basis}
                      </div>
                    ) : null}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{line.usage_qty}</td>
                  <td className="px-3 py-2">{line.meter_unit_snapshot || '—'}</td>
                  <td className="px-3 py-2 text-right">
                    {line.rate_snapshot != null && line.rate_snapshot !== '' ? (
                      <span className="tabular-nums">
                        {formatMoney(line.rate_snapshot)}
                        {line.meter_unit_snapshot ? (
                          <span className="text-gray-500"> / {line.meter_unit_snapshot}</span>
                        ) : null}
                      </span>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {line.amount != null ? formatMoney(line.amount) : '—'}
                  </td>
                  {canEdit ? (
                    <td className="px-3 py-2 text-right space-x-2">
                      <button
                        type="button"
                        className="text-[#1F6F5C] text-xs font-medium"
                        onClick={() => setEditMachine(line)}
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        className="text-red-600 text-xs"
                        onClick={() => {
                          if (window.confirm('Remove this machine line?')) {
                            deleteMachineM.mutate({ id: job.id, lineId: line.id });
                          }
                        }}
                      >
                        Remove
                      </button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {canEdit ? (
          <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
            <FormField label="Machine">
              <select
                id="fj-mac-m"
                value={addMach.machine_id}
                onChange={(e) => setAddMach((s) => ({ ...s, machine_id: e.target.value }))}
                className="rounded-lg border border-gray-300 px-2 py-1.5 text-sm min-w-[180px]"
              >
                <option value="">—</option>
                {(machines ?? []).map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.name}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Usage qty">
              <input
                id="fj-mac-q"
                type="number"
                step="any"
                min="0"
                value={addMach.usage_qty}
                onChange={(e) => setAddMach((s) => ({ ...s, usage_qty: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              />
            </FormField>
            <FormField label="Meter unit">
              <input
                id="fj-mac-u"
                value={addMach.meter_unit_snapshot}
                onChange={(e) => setAddMach((s) => ({ ...s, meter_unit_snapshot: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
                placeholder="e.g. ha"
              />
            </FormField>
            <FormField label="Rate snapshot (optional)">
              <input
                id="fj-mac-rs"
                type="number"
                step="any"
                min="0"
                value={addMach.rate_snapshot}
                onChange={(e) => setAddMach((s) => ({ ...s, rate_snapshot: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              />
            </FormField>
            <FormField label="Machinery cost (optional)">
              <input
                id="fj-mac-a"
                type="number"
                step="any"
                min="0"
                value={addMach.amount}
                onChange={(e) => setAddMach((s) => ({ ...s, amount: e.target.value }))}
                className="w-28 rounded-lg border border-gray-300 px-2 py-1.5 text-sm"
              />
            </FormField>
            <button
              type="button"
              disabled={
                addMachineM.isPending ||
                !addMach.machine_id ||
                !addMach.usage_qty ||
                parseFloat(addMach.usage_qty) < 0
              }
              onClick={async () => {
                await addMachineM.mutateAsync({
                  id: job.id,
                  payload: {
                    machine_id: addMach.machine_id,
                    usage_qty: parseFloat(addMach.usage_qty),
                    meter_unit_snapshot: addMach.meter_unit_snapshot.trim() || undefined,
                    rate_snapshot: addMach.rate_snapshot ? parseFloat(addMach.rate_snapshot) : undefined,
                    amount: addMach.amount ? parseFloat(addMach.amount) : undefined,
                  },
                });
                setAddMach({
                  machine_id: '',
                  usage_qty: '',
                  meter_unit_snapshot: '',
                  rate_snapshot: '',
                  amount: '',
                });
              }}
              className="rounded-lg bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-40"
            >
              Add machine line
            </button>
          </div>
        ) : null}
      </section>

      <FieldJobCostSummary job={job} />

      <Modal
        isOpen={showPostModal}
        onClose={() => setShowPostModal(false)}
        title={`${term('postAction')} field job`}
        testId="field-job-post-modal"
      >
        <p className="text-sm text-gray-600 mb-4">
          Posting records stock movements, labour payables, machinery usage, and machinery cost (from your rate card or
          optional line amounts) in the ledger. This action only runs when you confirm—nothing is posted automatically.
        </p>
        <FormField label="Posting date">
          <input
            id="fj-post-date"
            type="date"
            value={postingDate}
            onChange={(e) => setPostingDate(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </FormField>
        <div className="mt-4 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => setShowPostModal(false)}
            className="rounded-lg border border-gray-200 px-4 py-2 text-sm"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handlePost}
            disabled={postM.isPending}
            className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm font-medium text-white"
          >
            {postM.isPending ? 'Posting…' : 'Confirm post'}
          </button>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => setShowReverseModal(false)}
        title="Reverse field job"
        testId="field-job-reverse-modal"
      >
        <p className="text-sm text-gray-600 mb-4">
          Reversal creates offsetting entries. Use a reversal posting date your accounting policy allows.
        </p>
        <FormField label="Posting date">
          <input
            id="fj-rev-date"
            type="date"
            value={reversePostingDate}
            onChange={(e) => setReversePostingDate(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </FormField>
        <FormField label="Reason (optional)">
          <textarea
            id="fj-rev-reason"
            value={reverseReason}
            onChange={(e) => setReverseReason(e.target.value)}
            rows={2}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </FormField>
        <div className="mt-4 flex justify-end gap-2">
          <button
            type="button"
            onClick={() => setShowReverseModal(false)}
            className="rounded-lg border border-gray-200 px-4 py-2 text-sm"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleReverse}
            disabled={reverseM.isPending}
            className="rounded-lg bg-amber-700 px-4 py-2 text-sm font-medium text-white"
          >
            {reverseM.isPending ? 'Reversing…' : 'Confirm reverse'}
          </button>
        </div>
      </Modal>

      <Modal
        isOpen={!!editInput}
        onClose={() => setEditInput(null)}
        title="Edit input line"
        size="lg"
      >
        {editInput ? (
          <EditInputForm
            key={editInput.id}
            line={editInput}
            jobId={job.id}
            stores={stores ?? []}
            items={items ?? []}
            onCancel={() => setEditInput(null)}
            onSave={async (payload) => {
              await updateInputM.mutateAsync({ id: job.id, lineId: editInput.id, payload });
              setEditInput(null);
            }}
            isPending={updateInputM.isPending}
          />
        ) : null}
      </Modal>

      <Modal
        isOpen={!!editLabour}
        onClose={() => setEditLabour(null)}
        title="Edit labour line"
        size="lg"
      >
        {editLabour ? (
          <EditLabourForm
            key={editLabour.id}
            line={editLabour}
            jobId={job.id}
            workers={workers ?? []}
            onCancel={() => setEditLabour(null)}
            onSave={async (payload) => {
              await updateLabourM.mutateAsync({ id: job.id, lineId: editLabour.id, payload });
              setEditLabour(null);
            }}
            isPending={updateLabourM.isPending}
          />
        ) : null}
      </Modal>

      <Modal
        isOpen={!!editMachine}
        onClose={() => setEditMachine(null)}
        title="Edit machine line"
        size="lg"
      >
        {editMachine ? (
          <EditMachineForm
            key={editMachine.id}
            line={editMachine}
            jobId={job.id}
            machines={machines ?? []}
            onCancel={() => setEditMachine(null)}
            onSave={async (payload) => {
              await updateMachineM.mutateAsync({ id: job.id, lineId: editMachine.id, payload });
              setEditMachine(null);
            }}
            isPending={updateMachineM.isPending}
          />
        ) : null}
      </Modal>
    </div>
  );
}

function EditInputForm({
  line,
  jobId: _jobId,
  stores,
  items,
  onCancel,
  onSave,
  isPending,
}: {
  line: FieldJobInputLine;
  jobId: string;
  stores: { id: string; name: string }[];
  items: { id: string; name: string }[];
  onCancel: () => void;
  onSave: (p: { store_id?: string; item_id?: string; qty?: number }) => Promise<void>;
  isPending: boolean;
}) {
  const [store_id, setStoreId] = useState(line.store_id);
  const [item_id, setItemId] = useState(line.item_id);
  const [qty, setQty] = useState(line.qty);

  return (
    <div className="space-y-3">
      <FormField label="Store">
        <select
          value={store_id}
          onChange={(e) => setStoreId(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
          {stores.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Item">
        <select
          value={item_id}
          onChange={(e) => setItemId(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
          {items.map((it) => (
            <option key={it.id} value={it.id}>
              {it.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Qty">
        <input
          type="number"
          step="any"
          min="0"
          value={qty}
          onChange={(e) => setQty(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm tabular-nums"
        />
      </FormField>
      <div className="flex justify-end gap-2 pt-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">
          Cancel
        </button>
        <button
          type="button"
          disabled={isPending || parseFloat(qty) <= 0}
          onClick={() => onSave({ store_id, item_id, qty: parseFloat(qty) })}
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm text-white disabled:opacity-40"
        >
          {isPending ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

function EditLabourForm({
  line,
  workers,
  onCancel,
  onSave,
  isPending,
}: {
  line: FieldJobLabourLine;
  jobId: string;
  workers: { id: string; name: string }[];
  onCancel: () => void;
  onSave: (p: {
    worker_id?: string;
    rate_basis?: string | null;
    units?: number;
    rate?: number;
    amount?: number | null;
  }) => Promise<void>;
  isPending: boolean;
}) {
  const [worker_id, setWorkerId] = useState(line.worker_id);
  const [rate_basis, setRateBasis] = useState(line.rate_basis || 'DAILY');
  const [units, setUnits] = useState(line.units);
  const [rate, setRate] = useState(line.rate);
  const [amount, setAmount] = useState(line.amount != null ? String(line.amount) : '');

  return (
    <div className="space-y-3">
      <FormField label="Worker">
        <select
          value={worker_id}
          onChange={(e) => setWorkerId(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
          {workers.map((w) => (
            <option key={w.id} value={w.id}>
              {w.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Basis">
        <select
          value={rate_basis || 'DAILY'}
          onChange={(e) => setRateBasis(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
          <option value="DAILY">DAILY</option>
          <option value="HOURLY">HOURLY</option>
          <option value="PIECE">PIECE</option>
        </select>
      </FormField>
      <FormField label="Units">
        <input
          type="number"
          step="any"
          min="0"
          value={units}
          onChange={(e) => setUnits(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <FormField label="Rate">
        <input
          type="number"
          step="any"
          min="0"
          value={rate}
          onChange={(e) => setRate(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <FormField label="Amount (optional)">
        <input
          type="number"
          step="any"
          min="0"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <div className="flex justify-end gap-2 pt-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">
          Cancel
        </button>
        <button
          type="button"
          disabled={isPending || parseFloat(units) <= 0}
          onClick={() =>
            onSave({
              worker_id,
              rate_basis: rate_basis || null,
              units: parseFloat(units),
              rate: parseFloat(rate),
              amount: amount.trim() ? parseFloat(amount) : null,
            })
          }
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm text-white disabled:opacity-40"
        >
          {isPending ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

function EditMachineForm({
  line,
  machines,
  onCancel,
  onSave,
  isPending,
}: {
  line: FieldJobMachineLine;
  jobId: string;
  machines: { id: string; name: string }[];
  onCancel: () => void;
  onSave: (p: {
    machine_id?: string;
    usage_qty?: number;
    meter_unit_snapshot?: string | null;
    rate_snapshot?: number | null;
    amount?: number | null;
  }) => Promise<void>;
  isPending: boolean;
}) {
  const [machine_id, setMachineId] = useState(line.machine_id);
  const [usage_qty, setUsageQty] = useState(line.usage_qty);
  const [meter_unit_snapshot, setMeterUnit] = useState(line.meter_unit_snapshot || '');
  const [rate_snapshot, setRateSnap] = useState(line.rate_snapshot != null ? String(line.rate_snapshot) : '');
  const [amount, setAmount] = useState(line.amount != null ? String(line.amount) : '');

  return (
    <div className="space-y-3">
      <FormField label="Machine">
        <select
          value={machine_id}
          onChange={(e) => setMachineId(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        >
          {machines.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </select>
      </FormField>
      <FormField label="Usage qty">
        <input
          type="number"
          step="any"
          min="0"
          value={usage_qty}
          onChange={(e) => setUsageQty(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <FormField label="Meter unit">
        <input
          value={meter_unit_snapshot}
          onChange={(e) => setMeterUnit(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <FormField label="Rate snapshot (optional)">
        <input
          type="number"
          step="any"
          min="0"
          value={rate_snapshot}
          onChange={(e) => setRateSnap(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <FormField label="Machinery cost (optional)">
        <input
          type="number"
          step="any"
          min="0"
          value={amount}
          onChange={(e) => setAmount(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
        />
      </FormField>
      <div className="flex justify-end gap-2 pt-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-gray-200 px-4 py-2 text-sm">
          Cancel
        </button>
        <button
          type="button"
          disabled={isPending || parseFloat(usage_qty) < 0}
          onClick={() =>
            onSave({
              machine_id,
              usage_qty: parseFloat(usage_qty),
              meter_unit_snapshot: meter_unit_snapshot.trim() || null,
              rate_snapshot: rate_snapshot.trim() ? parseFloat(rate_snapshot) : null,
              amount: amount.trim() ? parseFloat(amount) : null,
            })
          }
          className="rounded-lg bg-[#1F6F5C] px-4 py-2 text-sm text-white disabled:opacity-40"
        >
          {isPending ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}
