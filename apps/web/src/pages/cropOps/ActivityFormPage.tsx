import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams, Link } from 'react-router-dom';
import { useCreateActivity, useActivityTypes } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProductionUnits } from '../../hooks/useProductionUnits';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useWorkers } from '../../hooks/useLabour';
import { useInventoryStores, useInventoryItems, useStockOnHand } from '../../hooks/useInventory';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import { useTenant } from '../../hooks/useTenant';
import { useFormAutosave } from '../../hooks/useFormAutosave';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { FormCard } from '../../components/FormLayout';
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
import { term } from '../../config/terminology';
import type { CreateCropActivityPayload } from '../../types';

type InputLine = { store_id: string; item_id: string; qty: string };
type LabourLine = { worker_id: string; rate_basis: string; units: string; rate: string };

type ActivitySnapshot = {
  doc_no: string;
  activity_type_id: string;
  activity_date: string;
  crop_cycle_id: string;
  project_id: string;
  production_unit_id: string;
  land_parcel_id: string;
  notes: string;
  inputs: InputLine[];
  labour: LabourLine[];
};

export default function ActivityFormPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { tenantId } = useTenant();
  const createM = useCreateActivity();
  const { data: activityTypes } = useActivityTypes();
  const { data: cropCycles } = useCropCycles();
  const { data: landParcels } = useLandParcels();
  const { data: workers } = useWorkers();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand({});
  const { formatMoney } = useFormatting();
  const { hasOrchardLivestockModule } = useOrchardLivestockAddonsEnabled();

  const [doc_no, setDocNo] = useState('');
  const [activity_type_id, setActivityTypeId] = useState('');
  const [activity_date, setActivityDate] = useState(new Date().toISOString().split('T')[0]);
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [notes, setNotes] = useState('');
  const [inputs, setInputs] = useState<InputLine[]>([{ store_id: '', item_id: '', qty: '' }]);
  const [labour, setLabour] = useState<LabourLine[]>([{ worker_id: '', rate_basis: 'DAILY', units: '', rate: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: productionUnits } = useProductionUnits();
  const { data: projectsForCrop } = useProjects(crop_cycle_id || undefined);

  // Defaults: doc_no, active cycle, last land parcel
  useEffect(() => {
    if (!doc_no) setDocNo(generateDocNo('ACT'));
  }, []);
  useEffect(() => {
    if (!cropCycles?.length) return;
    const activeId = getActiveCropCycleId(cropCycles);
    const storedId = getStored<string>(formStorageKeys.last_crop_cycle_id);
    const initial = storedId && cropCycles.some((c) => c.id === storedId) ? storedId : activeId;
    if (initial && !crop_cycle_id) setCropCycleId(initial);
  }, [cropCycles]);
  useEffect(() => {
    const stored = getStored<string>(formStorageKeys.last_land_parcel_id);
    if (stored && landParcels?.some((p) => p.id === stored) && !land_parcel_id) setLandParcelId(stored);
  }, [landParcels]);
  useEffect(() => {
    if (crop_cycle_id) setStored(formStorageKeys.last_crop_cycle_id, crop_cycle_id);
  }, [crop_cycle_id]);
  useEffect(() => {
    if (!productionUnits?.length) return;
    if (!hasOrchardLivestockModule) {
      setProductionUnitId('');
      return;
    }
    const fromUrl = searchParams.get('production_unit_id');
    if (fromUrl && productionUnits.some((u) => u.id === fromUrl)) {
      setProductionUnitId(fromUrl);
      return;
    }
    const stored = getStored<string>(formStorageKeys.last_production_unit_id);
    if (stored && productionUnits.some((u) => u.id === stored) && !production_unit_id) setProductionUnitId(stored);
  }, [productionUnits, searchParams, hasOrchardLivestockModule]);
  useEffect(() => {
    if (!hasOrchardLivestockModule) return;
    if (production_unit_id) setStored(formStorageKeys.last_production_unit_id, production_unit_id);
  }, [production_unit_id, hasOrchardLivestockModule]);
  useEffect(() => {
    if (land_parcel_id) setStored(formStorageKeys.last_land_parcel_id, land_parcel_id);
  }, [land_parcel_id]);

  const getSnapshot = useCallback(
    (): ActivitySnapshot => ({
      doc_no,
      activity_type_id,
      activity_date,
      crop_cycle_id,
      project_id,
      production_unit_id,
      land_parcel_id,
      notes,
      inputs: [...inputs],
      labour: [...labour],
    }),
    [
      doc_no,
      activity_type_id,
      activity_date,
      crop_cycle_id,
      project_id,
      production_unit_id,
      land_parcel_id,
      notes,
      inputs,
      labour,
    ]
  );

  const applySnapshot = useCallback((data: ActivitySnapshot) => {
    setDocNo(data.doc_no);
    setActivityTypeId(data.activity_type_id);
    setActivityDate(data.activity_date);
    setCropCycleId(data.crop_cycle_id);
    setProjectId(data.project_id);
    setProductionUnitId(data.production_unit_id);
    setLandParcelId(data.land_parcel_id);
    setNotes(data.notes);
    setInputs(data.inputs.length ? data.inputs : [{ store_id: '', item_id: '', qty: '' }]);
    setLabour(data.labour.length ? data.labour : [{ worker_id: '', rate_basis: 'DAILY', units: '', rate: '' }]);
  }, []);

  const { hasDraft, restore, discard, clearDraft } = useFormAutosave<ActivitySnapshot>({
    formId: 'activity',
    tenantId: tenantId || '',
    context: crop_cycle_id ? { crop_cycle_id } : undefined,
    getSnapshot,
    applySnapshot,
    debounceMs: 4000,
    disabled: !tenantId,
  });

  const handleUseLast = () => {
    const last = getLastSubmit<CreateCropActivityPayload>(tenantId || '', 'activity');
    if (!last) return;
    setDocNo(last.doc_no || '');
    setActivityTypeId(last.activity_type_id || '');
    setActivityDate(last.activity_date || '');
    setCropCycleId(last.crop_cycle_id || '');
    setProjectId(last.project_id || '');
    setProductionUnitId(last.production_unit_id || '');
    setLandParcelId(last.land_parcel_id || '');
    setNotes(last.notes || '');
    setInputs(
      last.inputs?.length
        ? last.inputs.map((l) => ({ store_id: l.store_id, item_id: l.item_id, qty: String(l.qty) }))
        : [{ store_id: '', item_id: '', qty: '' }]
    );
    setLabour(
      last.labour?.length
        ? last.labour.map((l) => ({
            worker_id: l.worker_id,
            rate_basis: (l.rate_basis as 'DAILY' | 'HOURLY' | 'PIECE') || 'DAILY',
            units: String(l.units),
            rate: String(l.rate),
          }))
        : [{ worker_id: '', rate_basis: 'DAILY', units: '', rate: '' }]
    );
  };
  const hasLast = !!tenantId && getLastSubmit(tenantId, 'activity') != null;

  const getAvail = (storeId: string, itemId: string) => {
    if (!storeId || !itemId) return '—';
    const r = stock?.find((s) => s.store_id === storeId && s.item_id === itemId);
    return r ? String(r.qty_on_hand) : '0';
  };

  const addInput = () => setInputs((l) => [...l, { store_id: '', item_id: '', qty: '' }]);
  const removeInput = (i: number) => setInputs((l) => l.filter((_, idx) => idx !== i));
  const updateInput = (i: number, f: Partial<InputLine>) =>
    setInputs((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const addLabour = () => setLabour((l) => [...l, { worker_id: '', rate_basis: 'DAILY', units: '', rate: '' }]);
  const removeLabour = (i: number) => setLabour((l) => l.filter((_, idx) => idx !== i));
  const updateLabour = (i: number, f: Partial<LabourLine>) =>
    setLabour((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const labourTotal = labour.reduce((s, l) => {
    const u = parseFloat(l.units);
    const r = parseFloat(l.rate);
    return s + (Number.isFinite(u) && Number.isFinite(r) ? u * r : 0);
  }, 0);

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    const finalDocNo = doc_no.trim() || generateDocNo('ACT');
    if (!finalDocNo) e.doc_no = 'Doc number is required';
    if (!activity_type_id) e.activity_type_id = `${term('activityTypeSingular')} is required`;
    if (!activity_date) e.activity_date = 'Work date is required';
    if (!crop_cycle_id) e.crop_cycle_id = 'Crop cycle is required';
    if (!project_id) e.project_id = 'Project is required';
    const validInputs = inputs.filter((l) => l.store_id && l.item_id && parseFloat(l.qty) > 0);
    const validLabour = labour.filter((l) => l.worker_id && parseFloat(l.units) > 0 && parseFloat(l.rate) >= 0);
    if (validInputs.length === 0 && validLabour.length === 0) e.lines = 'Add at least one input line or one labour line';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    if (!validate()) return;
    const finalDocNo = doc_no.trim() || generateDocNo('ACT');
    const validInputs = inputs
      .filter((l) => l.store_id && l.item_id && parseFloat(l.qty) > 0)
      .map((l) => ({ store_id: l.store_id, item_id: l.item_id, qty: parseFloat(l.qty) }));
    const validLabour = labour
      .filter((l) => l.worker_id && parseFloat(l.units) > 0 && parseFloat(l.rate) >= 0)
      .map((l) => ({
        worker_id: l.worker_id,
        rate_basis: l.rate_basis || undefined,
        units: parseFloat(l.units),
        rate: parseFloat(l.rate),
      }));
    const payload: CreateCropActivityPayload = {
      doc_no: finalDocNo,
      activity_type_id,
      activity_date,
      crop_cycle_id,
      project_id,
      production_unit_id: production_unit_id || undefined,
      land_parcel_id: land_parcel_id || undefined,
      notes: notes || undefined,
      inputs: validInputs.length ? validInputs : undefined,
      labour: validLabour.length ? validLabour : undefined,
    };
    const activity = await createM.mutateAsync(payload);
    setLastSubmit(tenantId || '', 'activity', payload);
    clearDraft();
    navigate(`/app/crop-ops/activities/${activity.id}`);
  };

  return (
    <PageContainer width="form" className="space-y-6">
      <PageHeader
        title={term('newActivity')}
        description="Record operational field work for crop cycles—materials, activity, and optional on-farm labour lines."
        helper="For worker pay and payables, use Labour work logs instead."
        backTo="/app/crop-ops/activities"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Field Work Logs', to: '/app/crop-ops/activities' },
          { label: term('newActivity') },
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
            <button
              type="button"
              onClick={handleUseLast}
              className="text-sm font-medium text-[#1F6F5C] hover:underline"
            >
              Use last values
            </button>
          </div>
        )}
        {/* Date */}
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Date</h2>
          <FormField label="Work date" required error={errors.activity_date}>
            <input
              type="date"
              value={activity_date}
              onChange={(e) => setActivityDate(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
        </section>

        {/* Field */}
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Field</h2>
          <FormField label="Land Parcel (optional)">
            <select
              value={land_parcel_id}
              onChange={(e) => setLandParcelId(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="">None</option>
              {landParcels?.map((p) => (
                <option key={p.id} value={p.id}>{p.name || p.id}</option>
              ))}
            </select>
          </FormField>
        </section>

        {/* Work: type, cycle, project */}
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Work</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <FormField label="Doc No" required error={errors.doc_no}>
              <input
                value={doc_no}
                onChange={(e) => setDocNo(e.target.value)}
                placeholder={generateDocNo('ACT')}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              />
            </FormField>
            <FormField label={term('activityTypeSingular')} required error={errors.activity_type_id}>
              <select
                value={activity_type_id}
                onChange={(e) => setActivityTypeId(e.target.value)}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              >
                <option value="">Select</option>
                {activityTypes?.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </FormField>
            <FormField label="Crop Cycle" required error={errors.crop_cycle_id}>
              <select
                value={crop_cycle_id}
                onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              >
                <option value="">Select</option>
                {cropCycles?.map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </FormField>
            <FormField label={term('fieldCycle')} required error={errors.project_id}>
              <select
                value={project_id}
                onChange={(e) => setProjectId(e.target.value)}
                disabled={!crop_cycle_id}
                className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] disabled:bg-gray-50"
              >
                <option value="">Select</option>
                {(projectsForCrop || []).map((p) => (
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
                  Optional operational tag for orchards, livestock, or other long-cycle units. Leave blank for typical seasonal crops — Crop
                  Cycle and Field Cycle are the usual scope.
                </p>
              </FormField>
            ) : null}
          </div>
        </section>

        {/* Labour — stacked cards */}
        <section className="space-y-4">
          <div className="flex justify-between items-center">
            <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Labour</h2>
            <button type="button" onClick={addLabour} className="text-sm font-medium text-[#1F6F5C] hover:underline">+ Add</button>
          </div>
          {(!workers || workers.length === 0) && (
            <p className="text-sm text-gray-600">
              No workers yet. <Link to="/app/labour/workers" className="text-[#1F6F5C] hover:underline font-medium">Add a worker</Link> to log labour.
            </p>
          )}
          <div className="space-y-3">
            {labour.map((line, i) => (
              <div key={i} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <FormField label="Worker">
                    <select
                      value={line.worker_id}
                      onChange={(e) => updateLabour(i, { worker_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    >
                      <option value="">Select</option>
                      {workers?.map((w) => (
                        <option key={w.id} value={w.id}>{w.name}</option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Basis">
                    <select
                      value={line.rate_basis}
                      onChange={(e) => updateLabour(i, { rate_basis: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
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
                      value={line.units}
                      onChange={(e) => updateLabour(i, { units: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    />
                  </FormField>
                  <FormField label="Rate">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      value={line.rate}
                      onChange={(e) => updateLabour(i, { rate: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    />
                  </FormField>
                </div>
                <div className="flex justify-end">
                  <button type="button" onClick={() => removeLabour(i)} className="text-sm text-red-600 hover:underline">Remove</button>
                </div>
              </div>
            ))}
          </div>
          <p className="font-medium text-gray-700">Labour total: <span className="tabular-nums">{formatMoney(labourTotal)}</span></p>
        </section>

        {/* Inputs — stacked cards */}
        <section className="space-y-4">
          <div className="flex justify-between items-center">
            <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Inputs</h2>
            <button type="button" onClick={addInput} className="text-sm font-medium text-[#1F6F5C] hover:underline">+ Add</button>
          </div>
          <div className="space-y-3">
            {inputs.map((line, i) => (
              <div key={i} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <FormField label="Store">
                    <select
                      value={line.store_id}
                      onChange={(e) => updateInput(i, { store_id: e.target.value, item_id: '' })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    >
                      <option value="">Select</option>
                      {stores?.map((s) => (
                        <option key={s.id} value={s.id}>{s.name}</option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Item">
                    <select
                      value={line.item_id}
                      onChange={(e) => updateInput(i, { item_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    >
                      <option value="">Select</option>
                      {items?.map((it) => (
                        <option key={it.id} value={it.id}>{it.name}</option>
                      ))}
                    </select>
                  </FormField>
                  <FormField label="Qty">
                    <input
                      type="number"
                      step="any"
                      min="0"
                      value={line.qty}
                      onChange={(e) => updateInput(i, { qty: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    />
                  </FormField>
                  <FormField label="Available">
                    <span className="block px-3 py-2 text-sm text-gray-600">{getAvail(line.store_id, line.item_id)}</span>
                  </FormField>
                </div>
                <div className="flex justify-end">
                  <button type="button" onClick={() => removeInput(i)} className="text-sm text-red-600 hover:underline">Remove</button>
                </div>
              </div>
            ))}
          </div>
        </section>

        <FormField label="Notes">
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            rows={2}
          />
        </FormField>

        {errors.lines && <p className="text-sm text-red-600">{errors.lines}</p>}

        <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t">
          <button
            type="button"
            onClick={() => navigate('/app/crop-ops/activities')}
            className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={createM.isPending}
            className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createM.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </FormCard>
    </PageContainer>
  );
}
