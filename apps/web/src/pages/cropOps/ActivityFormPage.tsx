import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateActivity, useActivityTypes } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useWorkers } from '../../hooks/useLabour';
import { useInventoryStores, useInventoryItems, useStockOnHand } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { CreateCropActivityPayload } from '../../types';

type InputLine = { store_id: string; item_id: string; qty: string };
type LabourLine = { worker_id: string; rate_basis: string; units: string; rate: string };

export default function ActivityFormPage() {
  const navigate = useNavigate();
  const createM = useCreateActivity();
  const { data: activityTypes } = useActivityTypes();
  const { data: cropCycles } = useCropCycles();
  const { data: landParcels } = useLandParcels();
  const { data: workers } = useWorkers();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand({});
  const { formatMoney } = useFormatting();

  const [doc_no, setDocNo] = useState('');
  const [activity_type_id, setActivityTypeId] = useState('');
  const [activity_date, setActivityDate] = useState(new Date().toISOString().split('T')[0]);
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [notes, setNotes] = useState('');
  const [inputs, setInputs] = useState<InputLine[]>([{ store_id: '', item_id: '', qty: '' }]);
  const [labour, setLabour] = useState<LabourLine[]>([{ worker_id: '', rate_basis: 'DAILY', units: '', rate: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { data: projectsForCrop } = useProjects(crop_cycle_id || undefined);

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
    if (!doc_no.trim()) e.doc_no = 'Doc number is required';
    if (!activity_type_id) e.activity_type_id = 'Activity type is required';
    if (!activity_date) e.activity_date = 'Activity date is required';
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
      doc_no: doc_no.trim(),
      activity_type_id,
      activity_date,
      crop_cycle_id,
      project_id,
      land_parcel_id: land_parcel_id || undefined,
      notes: notes || undefined,
      inputs: validInputs.length ? validInputs : undefined,
      labour: validLabour.length ? validLabour : undefined,
    };
    const activity = await createM.mutateAsync(payload);
    navigate(`/app/crop-ops/activities/${activity.id}`);
  };

  return (
    <div>
      <PageHeader
        title="Crop Ops → Activities → New Activity"
        backTo="/app/crop-ops/activities"
        breadcrumbs={[
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Activities', to: '/app/crop-ops/activities' },
          { label: 'New Activity' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-6">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FormField label="Doc No" required error={errors.doc_no}>
            <input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Activity Type" required error={errors.activity_type_id}>
            <select value={activity_type_id} onChange={(e) => setActivityTypeId(e.target.value)} className="w-full px-3 py-2 border rounded">
              <option value="">Select</option>
              {activityTypes?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
            <p className="text-sm text-gray-500 mt-1">Activity types represent operations like ploughing, sowing, spraying.</p>
          </FormField>
          <FormField label="Activity Date" required error={errors.activity_date}>
            <input type="date" value={activity_date} onChange={(e) => setActivityDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Crop Cycle" required error={errors.crop_cycle_id}>
            <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
              <option value="">Select</option>
              {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>
          <FormField label="Project" required error={errors.project_id}>
            <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded" disabled={!crop_cycle_id}>
              <option value="">Select</option>
              {(projectsForCrop || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </FormField>
          <FormField label="Land Parcel (optional)">
            <select value={land_parcel_id} onChange={(e) => setLandParcelId(e.target.value)} className="w-full px-3 py-2 border rounded">
              <option value="">None</option>
              {landParcels?.map((p) => <option key={p.id} value={p.id}>{p.name || p.id}</option>)}
            </select>
          </FormField>
          <div className="md:col-span-2">
            <FormField label="Notes">
              <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} />
            </FormField>
          </div>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Inputs</h3>
            <button type="button" onClick={addInput} className="text-sm text-[#1F6F5C]">+ Add</button>
          </div>
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Store</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Item</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Available</th>
                <th className="w-10" />
              </tr>
            </thead>
            <tbody>
              {inputs.map((line, i) => (
                <tr key={i}>
                  <td className="px-3 py-2">
                    <select value={line.store_id} onChange={(e) => updateInput(i, { store_id: e.target.value, item_id: '' })} className="w-full px-2 py-1 border rounded text-sm">
                      <option value="">Select</option>
                      {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                  </td>
                  <td className="px-3 py-2">
                    <select value={line.item_id} onChange={(e) => updateInput(i, { item_id: e.target.value })} className="w-full px-2 py-1 border rounded text-sm">
                      <option value="">Select</option>
                      {items?.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                    </select>
                  </td>
                  <td className="px-3 py-2">
                    <input type="number" step="any" min="0" value={line.qty} onChange={(e) => updateInput(i, { qty: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" />
                  </td>
                  <td className="px-3 py-2 text-sm">{getAvail(line.store_id, line.item_id)}</td>
                  <td><button type="button" onClick={() => removeInput(i)} className="text-red-600 text-sm">Del</button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div>
          <div className="flex justify-between items-center mb-2">
            <h3 className="font-medium">Labour</h3>
            <button type="button" onClick={addLabour} className="text-sm text-[#1F6F5C]">+ Add</button>
          </div>
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Worker</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Basis</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Units</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Rate</th>
                <th className="w-10" />
              </tr>
            </thead>
            <tbody>
              {labour.map((line, i) => (
                <tr key={i}>
                  <td className="px-3 py-2">
                    <select value={line.worker_id} onChange={(e) => updateLabour(i, { worker_id: e.target.value })} className="w-full px-2 py-1 border rounded text-sm">
                      <option value="">Select</option>
                      {workers?.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
                    </select>
                  </td>
                  <td className="px-3 py-2">
                    <select value={line.rate_basis} onChange={(e) => updateLabour(i, { rate_basis: e.target.value })} className="w-full px-2 py-1 border rounded text-sm">
                      <option value="DAILY">DAILY</option>
                      <option value="HOURLY">HOURLY</option>
                      <option value="PIECE">PIECE</option>
                    </select>
                  </td>
                  <td className="px-3 py-2">
                    <input type="number" step="any" min="0" value={line.units} onChange={(e) => updateLabour(i, { units: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" />
                  </td>
                  <td className="px-3 py-2">
                    <input type="number" step="any" min="0" value={line.rate} onChange={(e) => updateLabour(i, { rate: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" />
                  </td>
                  <td><button type="button" onClick={() => removeLabour(i)} className="text-red-600 text-sm">Del</button></td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="mt-2 font-medium">Labour total: <span className="tabular-nums">{formatMoney(labourTotal)}</span></p>
        </div>

        {errors.lines && <p className="text-sm text-red-600">{errors.lines}</p>}

        <div className="flex justify-end gap-2 pt-4">
          <button type="button" onClick={() => navigate('/app/crop-ops/activities')} className="px-4 py-2 border rounded">Cancel</button>
          <button onClick={handleSubmit} disabled={createM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50">
            {createM.isPending ? 'Creating...' : 'Create'}
          </button>
        </div>
      </div>
    </div>
  );
}
