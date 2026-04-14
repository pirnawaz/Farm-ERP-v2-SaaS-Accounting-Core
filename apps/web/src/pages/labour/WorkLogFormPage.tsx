import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useCreateWorkLog, useWorkers } from '../../hooks/useLabour';
import { useActivities } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProductionUnits } from '../../hooks/useProductionUnits';
import { useProjects } from '../../hooks/useProjects';
import { useMachinesQuery } from '../../hooks/useMachinery';
import { useModules } from '../../contexts/ModulesContext';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import { useTenant } from '../../hooks/useTenant';
import { useFormAutosave } from '../../hooks/useFormAutosave';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { FormActions, FormCard, FormSection } from '../../components/FormLayout';
import { useFormatting } from '../../hooks/useFormatting';
import {
  generateDocNo,
  getActiveCropCycleId,
  getStored,
  setStored,
  formStorageKeys,
  getLastSubmit,
  setLastSubmit,
} from '../../utils/formDefaults';

type WorkLogSnapshot = {
  doc_no: string;
  crop_cycle_id: string;
  project_id: string;
  production_unit_id: string;
  worker_id: string;
  work_date: string;
  activity_id: string;
  machine_id: string;
  rate_basis: 'DAILY' | 'HOURLY' | 'PIECE';
  units: string;
  rate: string;
  notes: string;
};

export default function WorkLogFormPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { tenantId } = useTenant();
  const createM = useCreateWorkLog();
  const { data: workers } = useWorkers({});
  const { data: cropCycles } = useCropCycles();
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  const { data: productionUnits } = useProductionUnits();
  const { data: projects } = useProjects(crop_cycle_id || undefined);
  const { data: activities } = useActivities(
    crop_cycle_id && project_id ? { crop_cycle_id, project_id } : undefined
  );
  const { isModuleEnabled } = useModules();
  const { hasOrchardLivestockModule } = useOrchardLivestockAddonsEnabled();
  const machineryEnabled = isModuleEnabled('machinery');
  const { data: machines } = useMachinesQuery(undefined);
  const { formatMoney } = useFormatting();

  const [doc_no, setDocNo] = useState('');
  const [worker_id, setWorkerId] = useState('');
  const [work_date, setWorkDate] = useState(new Date().toISOString().split('T')[0]);
  const [activity_id, setActivityId] = useState('');
  const [machine_id, setMachineId] = useState('');
  const [rate_basis, setRateBasis] = useState<'DAILY' | 'HOURLY' | 'PIECE'>('DAILY');
  const [units, setUnits] = useState('');
  const [rate, setRate] = useState('');
  const [notes, setNotes] = useState('');
  const [submitError, setSubmitError] = useState<string | null>(null);

  // Phase 1+2 legacy workflow cleanup: disable manual/legacy creation.
  // Keep list/detail access for historical records, but block this create form route.
  const legacyCreateDisabled = true;
  if (legacyCreateDisabled) {
    return (
      <PageContainer width="form" className="space-y-6 pb-8">
        <PageHeader
          title="New work log"
          description="This legacy/manual create path has been disabled. Use the primary workflow instead. Existing records remain available for history and testing."
          backTo="/app/labour/work-logs"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Labour Overview', to: '/app/labour' },
            { label: 'Work Logs', to: '/app/labour/work-logs' },
            { label: 'New' },
          ]}
        />
        <div className="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-950">
          This legacy/manual create path has been disabled. Use the primary workflow instead. Existing records remain available for history and testing.
        </div>
        <div className="flex justify-end">
          <button
            type="button"
            onClick={() => navigate('/app/labour/work-logs', { replace: true })}
            className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Back to Work Logs
          </button>
        </div>
      </PageContainer>
    );
  }

  useEffect(() => {
    if (!hasOrchardLivestockModule) {
      setProductionUnitId('');
    }
  }, [hasOrchardLivestockModule]);

  // Defaults: active crop cycle, last project, last worker, last rate
  useEffect(() => {
    if (!cropCycles?.length) return;
    const activeId = getActiveCropCycleId(cropCycles);
    const storedCycle = getStored<string>(formStorageKeys.last_crop_cycle_id);
    const initialCycle =
      storedCycle && cropCycles.some((c) => c.id === storedCycle) ? storedCycle : activeId;
    if (initialCycle && !crop_cycle_id) setCropCycleId(initialCycle);
  }, [cropCycles]);
  useEffect(() => {
    if (crop_cycle_id) setStored(formStorageKeys.last_crop_cycle_id, crop_cycle_id);
  }, [crop_cycle_id]);
  useEffect(() => {
    if (!projects?.length) return;
    const stored = getStored<string>(formStorageKeys.last_project_id);
    if (stored && projects.some((p) => p.id === stored) && !project_id) setProjectId(stored);
  }, [projects]);
  useEffect(() => {
    if (project_id) setStored(formStorageKeys.last_project_id, project_id);
  }, [project_id]);
  useEffect(() => {
    if (!productionUnits?.length) return;
    const fromUrl = searchParams.get('production_unit_id');
    if (fromUrl && productionUnits.some((u) => u.id === fromUrl)) {
      setProductionUnitId(fromUrl);
      return;
    }
    const stored = getStored<string>(formStorageKeys.last_production_unit_id);
    if (stored && productionUnits.some((u) => u.id === stored) && !production_unit_id) setProductionUnitId(stored);
  }, [productionUnits, searchParams]);
  useEffect(() => {
    if (production_unit_id) setStored(formStorageKeys.last_production_unit_id, production_unit_id);
  }, [production_unit_id]);
  useEffect(() => {
    const storedWorker = getStored<string>(formStorageKeys.last_worker_id);
    if (storedWorker && workers?.some((w) => w.id === storedWorker) && !worker_id)
      setWorkerId(storedWorker);
  }, [workers]);
  useEffect(() => {
    if (worker_id) setStored(formStorageKeys.last_worker_id, worker_id);
  }, [worker_id]);
  useEffect(() => {
    const storedRate = getStored<string>(formStorageKeys.last_labour_rate);
    if (storedRate != null && storedRate !== '' && !rate) setRate(String(storedRate));
  }, []);
  useEffect(() => {
    if (rate !== '') setStored(formStorageKeys.last_labour_rate, rate);
  }, [rate]);

  const getSnapshot = useCallback(
    (): WorkLogSnapshot => ({
      doc_no,
      crop_cycle_id,
      project_id,
      production_unit_id,
      worker_id,
      work_date,
      activity_id,
      machine_id,
      rate_basis,
      units,
      rate,
      notes,
    }),
    [
      doc_no,
      crop_cycle_id,
      project_id,
      production_unit_id,
      worker_id,
      work_date,
      activity_id,
      machine_id,
      rate_basis,
      units,
      rate,
      notes,
    ]
  );
  const applySnapshot = useCallback((data: WorkLogSnapshot) => {
    setDocNo(data.doc_no);
    setCropCycleId(data.crop_cycle_id);
    setProjectId(data.project_id);
    setProductionUnitId(data.production_unit_id);
    setWorkerId(data.worker_id);
    setWorkDate(data.work_date);
    setActivityId(data.activity_id);
    setMachineId(data.machine_id);
    setRateBasis(data.rate_basis);
    setUnits(data.units);
    setRate(data.rate);
    setNotes(data.notes);
  }, []);

  const { hasDraft, restore, discard, clearDraft } = useFormAutosave<WorkLogSnapshot>({
    formId: 'work-log',
    tenantId: tenantId || '',
    context: crop_cycle_id ? { crop_cycle_id } : undefined,
    getSnapshot,
    applySnapshot,
    debounceMs: 4000,
    disabled: !tenantId,
  });

  const handleUseLast = () => {
    const last = getLastSubmit<Record<string, unknown>>(tenantId || '', 'work-log');
    if (!last) return;
    if (typeof last.doc_no === 'string') setDocNo(last.doc_no);
    if (typeof last.crop_cycle_id === 'string') setCropCycleId(last.crop_cycle_id);
    if (typeof last.project_id === 'string') setProjectId(last.project_id);
    if (typeof last.production_unit_id === 'string') setProductionUnitId(last.production_unit_id);
    if (typeof last.worker_id === 'string') setWorkerId(last.worker_id);
    if (typeof last.work_date === 'string') setWorkDate(last.work_date);
    if (typeof last.activity_id === 'string') setActivityId(last.activity_id || '');
    if (typeof last.machine_id === 'string') setMachineId(last.machine_id || '');
    if (last.rate_basis === 'DAILY' || last.rate_basis === 'HOURLY' || last.rate_basis === 'PIECE')
      setRateBasis(last.rate_basis);
    if (typeof last.units === 'number') setUnits(String(last.units));
    else if (typeof last.units === 'string') setUnits(last.units);
    if (typeof last.rate === 'number') setRate(String(last.rate));
    else if (typeof last.rate === 'string') setRate(last.rate);
    if (typeof last.notes === 'string') setNotes(last.notes || '');
  };
  const hasLast = !!tenantId && getLastSubmit(tenantId, 'work-log') != null;

  const u = parseFloat(units || '0');
  const r = parseFloat(rate || '0');
  const amount = u * r;

  const handleSubmit = async () => {
    if (!worker_id || !work_date || !crop_cycle_id || !project_id || !rate_basis || !(u > 0) || !(r >= 0))
      return;
    const manualAck = searchParams.get('manual_exception_ack') === '1';
    const finalDocNo = doc_no.trim() || generateDocNo('WL');
    const payload = {
      doc_no: finalDocNo,
      worker_id,
      work_date,
      crop_cycle_id,
      project_id,
      production_unit_id: production_unit_id || undefined,
      activity_id: activity_id || undefined,
      machine_id: machine_id || undefined,
      rate_basis,
      units: u,
      rate: r,
      notes: notes || undefined,
      manual_exception_acknowledged: manualAck || undefined,
    };
    try {
      const log = await createM.mutateAsync(payload);
      setLastSubmit(tenantId || '', 'work-log', payload);
      clearDraft();
      navigate(`/app/labour/work-logs/${log.id}`);
    } catch (e: any) {
      const msg =
        e?.response?.data?.message ||
        e?.message ||
        'Unable to create labour work log. Use Field Jobs for normal crop-field work.';
      setSubmitError(String(msg));
    }
  };

  return (
    <PageContainer width="form" className="space-y-6 pb-8">
      <PageHeader
        title="New work log"
        description="Record paid labour time for a worker against crop and field cycles."
        helper="This drives payables—operational field work without labour pay is under Crop Ops field work logs."
        backTo="/app/labour/work-logs"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Labour Overview', to: '/app/labour' },
          { label: 'Work Logs', to: '/app/labour/work-logs' },
          { label: 'New' },
        ]}
      />
      {hasDraft && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center gap-2">
          <span>Restore draft?</span>
          <button type="button" onClick={restore} className="font-medium text-[#1F6F5C] hover:underline">
            Restore
          </button>
          <span>|</span>
          <button type="button" onClick={discard} className="font-medium text-gray-600 hover:underline">
            Discard
          </button>
        </div>
      )}
      <FormCard>
        {hasLast && (
          <div className="flex justify-end">
            <button type="button" onClick={handleUseLast} className="text-sm font-medium text-[#1F6F5C] hover:underline">
              Use last values
            </button>
          </div>
        )}
        {/* Date */}
        <FormSection title="Date">
          <FormField label="Work Date" required>
            <input
              type="date"
              value={work_date}
              onChange={(e) => setWorkDate(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
        </FormSection>

        {/* Work: cycle, project, activity, machine */}
        <FormSection title="Work">
          <div className="space-y-4">
            <FormField label="Crop Cycle" required>
              <select
                value={crop_cycle_id}
                onChange={(e) => {
                  setCropCycleId(e.target.value);
                  setProjectId('');
                  setActivityId('');
                }}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              >
                <option value="">Select crop cycle</option>
                {cropCycles?.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </FormField>
            <FormField label="Project" required>
              <select
                value={project_id}
                onChange={(e) => {
                  setProjectId(e.target.value);
                  setActivityId('');
                }}
                disabled={!crop_cycle_id}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] disabled:bg-gray-50"
              >
                <option value="">Select project</option>
                {(projects || []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </FormField>
            {hasOrchardLivestockModule ? (
              <FormField label="Orchard / Livestock / Long-cycle unit (optional)">
                <select
                  value={production_unit_id}
                  onChange={(e) => setProductionUnitId(e.target.value)}
                  className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                >
                  <option value="">None</option>
                  {(productionUnits ?? []).map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name}
                      {u.type === 'SEASONAL' ? ' (legacy seasonal)' : ''}
                    </option>
                  ))}
                </select>
                <p className="mt-1 text-xs text-gray-500">
                  Optional tag for orchards, livestock, or other long-cycle units. Leave blank for typical seasonal labour — the crop and field
                  cycle carry the main seasonal context.
                </p>
              </FormField>
            ) : null}
            <FormField label="Activity (optional)">
              <select
                value={activity_id}
                onChange={(e) => setActivityId(e.target.value)}
                disabled={!crop_cycle_id || !project_id}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] disabled:bg-gray-50"
              >
                <option value="">None</option>
                {activities?.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.doc_no} {a.type?.name ? `– ${a.type.name}` : ''}
                  </option>
                ))}
              </select>
            </FormField>
            {machineryEnabled && (
              <FormField label="Machine (optional)">
                <select
                  value={machine_id}
                  onChange={(e) => setMachineId(e.target.value)}
                  className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                >
                  <option value="">None</option>
                  {machines?.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.code} – {m.name}
                    </option>
                  ))}
                </select>
              </FormField>
            )}
          </div>
        </FormSection>

        {/* Labour */}
        <FormSection title="Labour">
          <div className="space-y-4">
            <FormField label="Worker" required>
              <select
                value={worker_id}
                onChange={(e) => setWorkerId(e.target.value)}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              >
                <option value="">Select worker</option>
                {workers?.map((w) => (
                  <option key={w.id} value={w.id}>{w.name}</option>
                ))}
              </select>
            </FormField>
            <FormField label="Rate basis" required>
              <select
                value={rate_basis}
                onChange={(e) => setRateBasis(e.target.value as 'DAILY' | 'HOURLY' | 'PIECE')}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              >
                <option value="DAILY">DAILY</option>
                <option value="HOURLY">HOURLY</option>
                <option value="PIECE">PIECE</option>
              </select>
            </FormField>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <FormField label="Units" required>
                <input
                  type="number"
                  step="any"
                  min="0"
                  value={units}
                  onChange={(e) => setUnits(e.target.value)}
                  className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                />
              </FormField>
              <FormField label="Rate" required>
                <input
                  type="number"
                  step="any"
                  min="0"
                  value={rate}
                  onChange={(e) => setRate(e.target.value)}
                  className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
                />
              </FormField>
            </div>
            <p className="font-medium text-gray-700">Amount: <span className="tabular-nums">{formatMoney(amount)}</span></p>
          </div>
        </FormSection>

        <FormField label="Doc No (optional)">
          <input
            value={doc_no}
            onChange={(e) => setDocNo(e.target.value)}
            placeholder="Leave blank to auto-generate"
            className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
          />
        </FormField>
        <FormField label="Notes">
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            rows={2}
          />
        </FormField>

        <FormActions>
          {submitError ? (
            <p className="w-full text-sm text-red-600" role="alert">
              {submitError}
            </p>
          ) : null}
          <button
            type="button"
            onClick={() => navigate('/app/labour/work-logs')}
            className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={
              createM.isPending ||
              !(u > 0) ||
              !(r >= 0) ||
              !worker_id ||
              !crop_cycle_id ||
              !project_id
            }
            className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createM.isPending ? 'Saving…' : 'Save'}
          </button>
        </FormActions>
      </FormCard>
    </PageContainer>
  );
}
