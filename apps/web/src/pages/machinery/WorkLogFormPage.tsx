import { useState, useEffect, useMemo } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  useCreateWorkLog,
  useUpdateWorkLog,
  useWorkLogQuery,
  useMachinesQuery,
} from '../../hooks/useMachinery';
import { useProjects } from '../../hooks/useProjects';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import type { MachineWorkLogPoolScope } from '../../types';
import { getStored, setStored, formStorageKeys } from '../../utils/formDefaults';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

const BENEFICIARY_OPTIONS: { value: MachineWorkLogPoolScope; label: string }[] = [
  { value: 'LANDLORD_ONLY', label: 'My farm' },
  { value: 'SHARED', label: 'Shared' },
  { value: 'HARI_ONLY', label: 'Hari only' },
];

export default function WorkLogFormPage() {
  const { id } = useParams<{ id: string }>();
  const isEdit = Boolean(id && id !== 'new');
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { data: workLog, isLoading: loadingLog } = useWorkLogQuery(id ?? '');
  const { data: machines } = useMachinesQuery();
  const { data: projects } = useProjects();
  const createM = useCreateWorkLog();
  const updateM = useUpdateWorkLog();

  const [machine_id, setMachineId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [pool_scope, setPoolScope] = useState<MachineWorkLogPoolScope>('SHARED');
  const [work_date, setWorkDate] = useState(new Date().toISOString().split('T')[0]);
  const [meter_start, setMeterStart] = useState('');
  const [meter_end, setMeterEnd] = useState('');
  const [notes, setNotes] = useState('');
  const [chargeable, setChargeable] = useState(false);
  const [internal_charge_rate, setInternalChargeRate] = useState('');

  useEffect(() => {
    if (isEdit || !machines?.length) return;
    if (machines.length === 1 && !machine_id) setMachineId(machines[0].id);
  }, [isEdit, machines, machine_id]);

  useEffect(() => {
    if (isEdit || !projects?.length) return;
    if (projects.length === 1 && !project_id) setProjectId(projects[0].id);
  }, [isEdit, projects, project_id]);

  useEffect(() => {
    if (isEdit || !machines?.length || machine_id) return;
    const last = getStored<string>(formStorageKeys.last_machinery_machine_id);
    if (last && machines.some((m) => m.id === last)) setMachineId(last);
  }, [isEdit, machines, machine_id]);

  useEffect(() => {
    if (isEdit || !projects?.length || project_id) return;
    const last = getStored<string>(formStorageKeys.last_machinery_project_id);
    if (last && projects.some((p) => p.id === last)) setProjectId(last);
  }, [isEdit, projects, project_id]);

  useEffect(() => {
    if (!workLog || !isEdit) return;
    setMachineId(workLog.machine_id);
    setProjectId(workLog.project_id);
    setPoolScope((workLog.pool_scope as MachineWorkLogPoolScope) || 'SHARED');
    setWorkDate(workLog.work_date ?? new Date().toISOString().split('T')[0]);
    setMeterStart(workLog.meter_start ?? '');
    setMeterEnd(workLog.meter_end ?? '');
    setNotes(workLog.notes ?? '');
    setChargeable(Boolean(workLog.chargeable));
    setInternalChargeRate(
      workLog.internal_charge_rate != null && workLog.internal_charge_rate !== ''
        ? String(workLog.internal_charge_rate)
        : ''
    );
  }, [workLog, isEdit]);

  const selectedProject = projects?.find((p) => p.id === project_id);
  const usageQty =
    meter_start !== '' && meter_end !== ''
      ? Math.max(0, parseFloat(meter_end) - parseFloat(meter_start))
      : 0;

  const meterValid =
    meter_start === '' ||
    meter_end === '' ||
    parseFloat(meter_end) >= parseFloat(meter_start);
  const rateNum = internal_charge_rate !== '' ? parseFloat(internal_charge_rate) : NaN;
  const chargePreview =
    chargeable && !Number.isNaN(rateNum) && usageQty > 0 ? (Math.round(rateNum * usageQty * 100) / 100).toFixed(2) : null;

  const chargeableBlockedReason =
    chargeable && (!project_id
      ? 'Choose a field cycle so the charge can be booked to a project.'
      : usageQty <= 0
        ? 'Enter meter readings so usage is greater than zero.'
        : Number.isNaN(rateNum) || rateNum <= 0
          ? 'Enter a rate greater than zero (per meter unit).'
          : null);

  const canSubmit =
    machine_id &&
    project_id &&
    meterValid &&
    !chargeableBlockedReason;

  const readinessItems = useMemo(
    () => [
      { ok: Boolean(machine_id), label: 'Machine selected' },
      { ok: Boolean(project_id), label: 'Field cycle selected' },
      { ok: meterValid, label: 'Meter readings valid (end ≥ start)' },
      {
        ok: !chargeable || !chargeableBlockedReason,
        label: chargeable ? 'Chargeable rules: project, usage > 0, rate > 0' : 'Not charging project (optional)',
      },
    ],
    [machine_id, project_id, meterValid, chargeable, chargeableBlockedReason]
  );

  const handleSubmit = async () => {
    if (!canSubmit) return;
    const manualAck = searchParams.get('manual_exception_ack') === '1';
    // Always send boolean `chargeable` so updates can turn internal charging off (`false` must not be stripped).
    const payload = {
      machine_id,
      project_id,
      pool_scope: pool_scope || undefined,
      work_date: work_date || undefined,
      meter_start: meter_start !== '' ? parseFloat(meter_start) : undefined,
      meter_end: meter_end !== '' ? parseFloat(meter_end) : undefined,
      notes: notes || undefined,
      manual_exception_acknowledged: manualAck || undefined,
      chargeable,
      internal_charge_rate:
        chargeable && internal_charge_rate !== '' && !Number.isNaN(parseFloat(internal_charge_rate))
          ? parseFloat(internal_charge_rate)
          : undefined,
    };

    if (isEdit && id) {
      await updateM.mutateAsync({ id, payload });
      setStored(formStorageKeys.last_machinery_machine_id, machine_id);
      setStored(formStorageKeys.last_machinery_project_id, project_id);
      navigate(`/app/machinery/work-logs/${id}`);
    } else {
      const created = await createM.mutateAsync(payload);
      setStored(formStorageKeys.last_machinery_machine_id, machine_id);
      setStored(formStorageKeys.last_machinery_project_id, project_id);
      navigate(`/app/machinery/work-logs/${created.id}`);
    }
  };

  if (isEdit && loadingLog) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Edit machine usage"
          backTo="/app/machinery/work-logs"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine work entries', to: '/app/machinery/work-logs' },
            { label: '…' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      </div>
    );
  }
  if (isEdit && id && !workLog) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Machine usage"
          backTo="/app/machinery/work-logs"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine work entries', to: '/app/machinery/work-logs' },
            { label: 'Not found' },
          ]}
        />
        <p className="text-gray-600">Machine usage record not found.</p>
      </div>
    );
  }
  if (isEdit && workLog && workLog.status !== 'DRAFT') {
    return (
      <div className="space-y-6">
        <PageHeader
          title={`Machine usage ${workLog.work_log_no}`}
          backTo={`/app/machinery/work-logs/${id}`}
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine work entries', to: '/app/machinery/work-logs' },
            { label: workLog.work_log_no },
          ]}
        />
        <p className="text-gray-600">Only draft machine usage records can be edited.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-8">
      <PageHeader
        title={isEdit ? `Edit machine usage ${workLog?.work_log_no ?? ''}` : 'New machine usage'}
        description="Work entry: log meter usage for a field cycle. If chargeable, recording it later bills the field cycle and credits this machine’s income."
        helper="For repairs and servicing, use maintenance jobs. For normal field work, Field Jobs are usually best."
        backTo={isEdit ? `/app/machinery/work-logs/${id}` : '/app/machinery/work-logs'}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine work entries', to: '/app/machinery/work-logs' },
          { label: isEdit ? (workLog?.work_log_no ?? 'Edit') : 'New' },
        ]}
      />
      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <p className="text-sm text-gray-600">
          Saving stores a <span className="font-medium text-gray-800">draft</span>. Use <span className="font-medium text-gray-800">Record to accounts</span> on the detail page when you are ready to post costs.
        </p>
        <PrePostChecklist
          items={readinessItems}
          blockingHint={!canSubmit ? 'Complete required fields before saving this draft.' : undefined}
        />
        {(createM.isError || updateM.isError) && (
          <OperatorErrorCallout
            error={formatOperatorError(createM.isError ? createM.error : updateM.error)}
          />
        )}
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
          <p className="font-medium">Ordinary usage vs charge to project</p>
          <p className="mt-1 text-amber-900/90">
            <strong>Usage only</strong> — record hours and beneficiary; no money on post.{' '}
            <strong>Charge this job to project</strong> — when you post, the field cycle is charged that amount and the machine shows the same amount
            as income (farm total unchanged; project and machine see the movement).
          </p>
        </div>
        {chargeable && (
          <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-950">
            <p className="font-medium">When you post this log</p>
            <ul className="mt-2 list-disc list-inside space-y-1">
              <li>The selected field cycle (project) will be charged the calculated amount.</li>
              <li>The machine will record that amount as machine income.</li>
            </ul>
          </div>
        )}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Machine" required>
            <select
              value={machine_id}
              onChange={(e) => setMachineId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={isEdit}
            >
              <option value="">Select machine</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name} ({m.code})
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Field cycle" required>
            <select
              value={project_id}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              disabled={isEdit}
            >
              <option value="">Select field cycle</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Crop cycle (from field cycle)">
            <span className="text-gray-700">
              {selectedProject?.crop_cycle?.name ?? '—'}
            </span>
          </FormField>
          <FormField label="Beneficiary">
            <select
              value={pool_scope}
              onChange={(e) => setPoolScope(e.target.value as MachineWorkLogPoolScope)}
              className="w-full px-3 py-2 border rounded"
              disabled={isEdit}
            >
              {BENEFICIARY_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Work date">
            <input
              type="date"
              value={work_date}
              onChange={(e) => setWorkDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>
          <FormField label="Meter start">
            <input
              type="number"
              step="0.01"
              min="0"
              value={meter_start}
              onChange={(e) => setMeterStart(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              placeholder="0"
            />
          </FormField>
          <FormField label="Meter end">
            <input
              type="number"
              step="0.01"
              min="0"
              value={meter_end}
              onChange={(e) => setMeterEnd(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              placeholder="0"
            />
          </FormField>
          <FormField label="Usage (computed)">
            <span className="tabular-nums">{usageQty}</span>
            {!meterValid && meter_start !== '' && meter_end !== '' && (
              <p className="mt-1 text-sm text-red-600">Meter end must be ≥ meter start.</p>
            )}
          </FormField>
          <div className="md:col-span-2 flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-3">
            <input
              id="wl-chargeable"
              type="checkbox"
              checked={chargeable}
              onChange={(e) => {
                setChargeable(e.target.checked);
                if (!e.target.checked) setInternalChargeRate('');
              }}
              className="mt-1"
            />
            <div className="flex-1 space-y-2">
              <label htmlFor="wl-chargeable" className="text-sm font-medium text-gray-900 cursor-pointer">
                Charge this job to the project (rate × usage)
              </label>
              {chargeable && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <FormField label="Rate (per meter unit)" required>
                    <input
                      type="number"
                      step="0.0001"
                      min="0"
                      value={internal_charge_rate}
                      onChange={(e) => setInternalChargeRate(e.target.value)}
                      className="w-full px-3 py-2 border rounded tabular-nums"
                      placeholder="0"
                    />
                  </FormField>
                  <FormField label="Calculated charge (when you post)">
                    <span className="tabular-nums text-gray-900 font-medium">
                      {chargePreview != null ? chargePreview : '—'}
                    </span>
                    {chargePreview != null && (
                      <p className="mt-1 text-xs text-gray-500">Same amount bills the project and credits machine income.</p>
                    )}
                  </FormField>
                </div>
              )}
              {chargeable && chargeableBlockedReason && (
                <p className="text-sm text-amber-800">{chargeableBlockedReason}</p>
              )}
            </div>
          </div>
          <div className="md:col-span-2">
            <FormField label="Notes">
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                className="w-full px-3 py-2 border rounded"
                rows={2}
              />
            </FormField>
          </div>
        </div>

        <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
          <button
            type="button"
            onClick={() => navigate(isEdit ? `/app/machinery/work-logs/${id}` : '/app/machinery/work-logs')}
            className="w-full sm:w-auto px-4 py-2 border rounded"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSubmit}
            disabled={!canSubmit || createM.isPending || updateM.isPending}
            title={!canSubmit ? 'Complete the checklist above before saving.' : undefined}
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50 min-h-[44px]"
          >
            {isEdit ? (updateM.isPending ? 'Saving…' : 'Save') : createM.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}
