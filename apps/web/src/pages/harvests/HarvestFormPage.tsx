import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useCreateHarvest, useAddHarvestLine } from '../../hooks/useHarvests';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProductionUnits } from '../../hooks/useProductionUnits';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { useOrchardLivestockAddonsEnabled } from '../../hooks/useModules';
import { useTenant } from '../../hooks/useTenant';
import { useFormAutosave } from '../../hooks/useFormAutosave';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { PageContainer } from '../../components/PageContainer';
import { FormCard } from '../../components/FormLayout';
import { harvestSchema } from '../../validation/harvestSchema';
import { getActiveCropCycleId, getStored, setStored, formStorageKeys, getLastSubmit, setLastSubmit } from '../../utils/formDefaults';
import toast from 'react-hot-toast';
import type { CreateHarvestPayload } from '../../types';
import { term } from '../../config/terminology';
import { PrimaryWorkflowBanner } from '../../components/workflow/PrimaryWorkflowBanner';

type HarvestLineForm = { inventory_item_id: string; store_id: string; quantity: string; uom: string; notes: string };

type HarvestSnapshot = {
  harvest_no: string;
  crop_cycle_id: string;
  project_id: string;
  production_unit_id: string;
  harvest_date: string;
  notes: string;
  lines: HarvestLineForm[];
};

export default function HarvestFormPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { tenantId } = useTenant();
  const createM = useCreateHarvest();
  const addLineM = useAddHarvestLine();
  const { data: cropCycles } = useCropCycles();
  const { data: productionUnits } = useProductionUnits();
  const { hasOrchardLivestockModule } = useOrchardLivestockAddonsEnabled();
  const [crop_cycle_id, setCropCycleId] = useState('');
  const { data: projectsForCrop } = useProjects(crop_cycle_id || undefined);
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);

  const [harvest_no, setHarvestNo] = useState('');
  const [project_id, setProjectId] = useState('');
  const [production_unit_id, setProductionUnitId] = useState('');
  useEffect(() => {
    if (!hasOrchardLivestockModule) {
      setProductionUnitId('');
    }
  }, [hasOrchardLivestockModule]);
  const [harvest_date, setHarvestDate] = useState(new Date().toISOString().split('T')[0]);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<HarvestLineForm[]>([{ inventory_item_id: '', store_id: '', quantity: '', uom: '', notes: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!cropCycles?.length) return;
    const activeId = getActiveCropCycleId(cropCycles);
    const storedId = getStored<string>(formStorageKeys.last_crop_cycle_id);
    const initial = storedId && cropCycles.some((c) => c.id === storedId) ? storedId : activeId;
    if (initial && !crop_cycle_id) setCropCycleId(initial);
  }, [cropCycles]);
  useEffect(() => {
    if (crop_cycle_id) setStored(formStorageKeys.last_crop_cycle_id, crop_cycle_id);
  }, [crop_cycle_id]);
  useEffect(() => {
    if (!projectsForCrop?.length) return;
    const stored = getStored<string>(formStorageKeys.last_project_id);
    if (stored && projectsForCrop.some((p) => p.id === stored) && !project_id) setProjectId(stored);
  }, [projectsForCrop]);
  useEffect(() => {
    if (project_id) setStored(formStorageKeys.last_project_id, project_id);
  }, [project_id]);
  useEffect(() => {
    if (!productionUnits?.length) return;
    if (!hasOrchardLivestockModule) return;
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

  const addLine = () => setLines((l) => [...l, { inventory_item_id: '', store_id: '', quantity: '', uom: '', notes: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<HarvestLineForm>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getSnapshot = useCallback(
    (): HarvestSnapshot => ({
      harvest_no,
      crop_cycle_id,
      project_id,
      production_unit_id,
      harvest_date,
      notes,
      lines: [...lines],
    }),
    [harvest_no, crop_cycle_id, project_id, production_unit_id, harvest_date, notes, lines]
  );
  const applySnapshot = useCallback((data: HarvestSnapshot) => {
    setHarvestNo(data.harvest_no);
    setCropCycleId(data.crop_cycle_id);
    setProjectId(data.project_id);
    setProductionUnitId(data.production_unit_id);
    setHarvestDate(data.harvest_date);
    setNotes(data.notes);
    setLines(data.lines.length ? data.lines : [{ inventory_item_id: '', store_id: '', quantity: '', uom: '', notes: '' }]);
  }, []);

  const { hasDraft, restore, discard, clearDraft } = useFormAutosave<HarvestSnapshot>({
    formId: 'harvest',
    tenantId: tenantId || '',
    context: crop_cycle_id ? { crop_cycle_id } : undefined,
    getSnapshot,
    applySnapshot,
    debounceMs: 4000,
    disabled: !tenantId,
  });

  const handleUseLast = () => {
    const last = getLastSubmit<HarvestSnapshot>(tenantId || '', 'harvest');
    if (!last) return;
    applySnapshot(last);
  };
  const hasLast = !!tenantId && getLastSubmit(tenantId, 'harvest') != null;

  const handleSubmit = async () => {
    // Prepare form data
    const validLines = lines.filter((l) => l.inventory_item_id && l.store_id && parseFloat(l.quantity) > 0);
    const formData = {
      crop_cycle_id,
      project_id,
      harvest_date,
      harvest_no: harvest_no || null,
      notes: notes || null,
      lines: validLines.map(l => ({
        inventory_item_id: l.inventory_item_id,
        store_id: l.store_id,
        quantity: l.quantity,
        uom: l.uom || null,
        notes: l.notes || null,
      })),
    };

    // Validate with zod
    try {
      harvestSchema.parse(formData);
      setErrors({});
    } catch (error: any) {
      if (error.errors) {
        const zodErrors: Record<string, string> = {};
        error.errors.forEach((err: any) => {
          const path = err.path.join('.');
          zodErrors[path] = err.message;
        });
        setErrors(zodErrors);
        toast.error('Please fix validation errors');
        return;
      }
    }

    try {
      const payload: CreateHarvestPayload = {
        harvest_no: harvest_no || undefined,
        crop_cycle_id,
        project_id,
        production_unit_id: production_unit_id || undefined,
        harvest_date,
        notes: notes || undefined,
      };

      const harvest = await createM.mutateAsync(payload);
      const hId = harvest.id;

      // Add lines
      for (const line of validLines) {
        await addLineM.mutateAsync({
          id: hId,
          payload: {
            inventory_item_id: line.inventory_item_id,
            store_id: line.store_id,
            quantity: parseFloat(line.quantity),
            uom: line.uom || undefined,
            notes: line.notes || undefined,
          },
        });
      }

      setLastSubmit(tenantId || '', 'harvest', getSnapshot());
      clearDraft();
      navigate(`/app/harvests/${hId}`);
    } catch (err) {
      // Error handled by mutation
    }
  };

  return (
    <PageContainer width="form" className="space-y-6 pb-8">
      <PageHeader
        title="New harvest"
        description="Record harvest quantities against crop and field cycles."
        helper="Use inventory lines when stock should move into a store; posting finalizes the harvest record."
        backTo="/app/harvests"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Harvests', to: '/app/harvests' },
          { label: 'New' },
        ]}
      />
      <PrimaryWorkflowBanner variant="harvest" />
      {hasDraft && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex flex-wrap items-center gap-2">
          <span>Restore draft?</span>
          <button type="button" onClick={restore} className="font-medium text-[#1F6F5C] hover:underline">Restore</button>
          <span>|</span>
          <button type="button" onClick={discard} className="font-medium text-gray-600 hover:underline">Discard</button>
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
        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Date</h2>
          <FormField label="Harvest Date *" error={errors.harvest_date}>
            <input
              type="date"
              value={harvest_date}
              onChange={(e) => setHarvestDate(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </FormField>
        </section>

        <section className="space-y-4">
          <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Work</h2>
          <FormField label="Harvest No (optional)" error={errors.harvest_no}>
            <input
              type="text"
              value={harvest_no}
              onChange={(e) => setHarvestNo(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
              placeholder="Optional"
            />
          </FormField>
          <FormField label="Crop Cycle *" error={errors.crop_cycle_id}>
            <select
              value={crop_cycle_id}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setProjectId('');
              }}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="">Select crop cycle</option>
              {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label={`${term('fieldCycle')} *`} error={errors.project_id}>
            <select
              value={project_id}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] disabled:bg-gray-50"
              disabled={!crop_cycle_id}
            >
              <option value="">{`Select ${term('fieldCycle').toLowerCase()}`}</option>
              {(projectsForCrop ?? []).map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </select>
            {crop_cycle_id && !(projectsForCrop?.length) && (
              <p className="text-sm text-gray-500 mt-1">No {term('fieldCycles').toLowerCase()} in this crop cycle. Create one first.</p>
            )}
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
                Optional tag for orchard, livestock, or long-cycle harvests. Leave blank for standard seasonal harvests where the crop cycle
                is enough.
              </p>
            </FormField>
          ) : null}
        </section>

        <section className="space-y-4">
          <div className="flex justify-between items-center">
            <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">Lines *</h2>
            <button type="button" onClick={addLine} className="text-sm font-medium text-[#1F6F5C] hover:underline">+ Add Line</button>
          </div>
          {errors.lines && <p className="text-red-600 text-sm">{errors.lines}</p>}
          <div className="space-y-3">
            {lines.map((line, idx) => (
              <div key={idx} className="border border-gray-200 rounded-lg p-4 bg-gray-50/50 space-y-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <FormField label="Item">
                    <select
                      value={line.inventory_item_id}
                      onChange={(e) => updateLine(idx, { inventory_item_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    >
                      <option value="">Item</option>
                      {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                    </select>
                  </FormField>
                  <FormField label="Store">
                    <select
                      value={line.store_id}
                      onChange={(e) => updateLine(idx, { store_id: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    >
                      <option value="">Store</option>
                      {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                  </FormField>
                  <FormField label="Qty">
                    <input
                      type="number"
                      step="0.001"
                      value={line.quantity}
                      onChange={(e) => updateLine(idx, { quantity: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                      placeholder="Qty"
                    />
                  </FormField>
                  <FormField label="UOM">
                    <input
                      type="text"
                      value={line.uom}
                      onChange={(e) => updateLine(idx, { uom: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                      placeholder="UOM"
                    />
                  </FormField>
                </div>
                <FormField label="Notes">
                  <input
                    type="text"
                    value={line.notes}
                    onChange={(e) => updateLine(idx, { notes: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                    placeholder="Line notes"
                  />
                </FormField>
                <div className="flex justify-end">
                  <button type="button" onClick={() => removeLine(idx)} className="text-sm text-red-600 hover:underline">Remove</button>
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
            rows={3}
          />
        </FormField>

        <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-4 border-t">
          <button type="button" onClick={() => navigate('/app/harvests')} className="w-full sm:w-auto px-4 py-2.5 border border-gray-300 rounded-lg hover:bg-gray-50">
            Cancel
          </button>
          <button
            onClick={handleSubmit}
            disabled={createM.isPending || addLineM.isPending}
            className="w-full sm:w-auto px-4 py-2.5 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
          >
            {createM.isPending || addLineM.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </FormCard>
    </PageContainer>
  );
}
