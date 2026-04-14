import { useState, useEffect } from 'react';
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
  const [submitError, setSubmitError] = useState<string | null>(null);

  // Phase 1+2 legacy workflow cleanup: disable manual/legacy creation.
  // Keep list/detail access for historical records, but block this create form route.
  const legacyCreateDisabled = true;
  if (legacyCreateDisabled && !isEdit) {
    return (
      <div className="space-y-6 pb-8">
        <PageHeader
          title="New machine usage"
          description="This legacy/manual create path has been disabled. Use the primary workflow instead. Existing records remain available for history and testing."
          backTo="/app/machinery/work-logs"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
            { label: 'New' },
          ]}
        />
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
          This legacy/manual create path has been disabled. Use the primary workflow instead. Existing records remain available for history and testing.
        </div>
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => navigate('/app/machinery/work-logs', { replace: true })}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Back to Machine Usage
          </button>
        </div>
      </div>
    );
  }

  useEffect(() => {
    if (!workLog || !isEdit) return;
    setMachineId(workLog.machine_id);
    setProjectId(workLog.project_id);
    setPoolScope((workLog.pool_scope as MachineWorkLogPoolScope) || 'SHARED');
    setWorkDate(workLog.work_date ?? new Date().toISOString().split('T')[0]);
    setMeterStart(workLog.meter_start ?? '');
    setMeterEnd(workLog.meter_end ?? '');
    setNotes(workLog.notes ?? '');
    // This form is usage-only. Costing flows through rate cards / posting (or downstream charges), not manual cost lines.
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
  const canSubmit =
    machine_id &&
    project_id &&
    meterValid;

  const handleSubmit = async () => {
    if (!canSubmit) return;
    const manualAck = searchParams.get('manual_exception_ack') === '1';
    const payload = {
      machine_id,
      project_id,
      pool_scope: pool_scope || undefined,
      work_date: work_date || undefined,
      meter_start: meter_start !== '' ? parseFloat(meter_start) : undefined,
      meter_end: meter_end !== '' ? parseFloat(meter_end) : undefined,
      notes: notes || undefined,
      manual_exception_acknowledged: manualAck || undefined,
    };

    if (isEdit && id) {
      await updateM.mutateAsync({ id, payload });
      navigate(`/app/machinery/work-logs/${id}`);
    } else {
      try {
        const created = await createM.mutateAsync(payload);
        navigate(`/app/machinery/work-logs/${created.id}`);
      } catch (e: any) {
        const msg =
          e?.response?.data?.message ||
          e?.message ||
          'Unable to create machine usage. Use Field Jobs for normal crop-field work.';
        setSubmitError(String(msg));
      }
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
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
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
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
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
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
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
        description="Record machine work against a field cycle and split costs (fuel, operator, maintenance, other)."
        helper="For repairs and scheduled servicing, use maintenance jobs or service history—not this form."
        backTo={isEdit ? `/app/machinery/work-logs/${id}` : '/app/machinery/work-logs'}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Machine Usage', to: '/app/machinery/work-logs' },
          { label: isEdit ? (workLog?.work_log_no ?? 'Edit') : 'New' },
        ]}
      />
      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
          <p className="font-medium">Usage-only manual entry</p>
          <p className="mt-1 text-amber-900/90">
            For normal crop-field work, capture machinery on a Field Job. This form records meter usage only; costing is derived later on posting
            (for example via rate cards / downstream documents).
          </p>
        </div>
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
          {submitError ? (
            <p className="w-full text-sm text-red-600" role="alert">
              {submitError}
            </p>
          ) : null}
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
            className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {isEdit ? (updateM.isPending ? 'Saving…' : 'Save') : createM.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}
