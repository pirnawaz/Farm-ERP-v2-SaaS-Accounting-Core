import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCreateHarvest, useAddHarvestLine } from '../../hooks/useHarvests';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { harvestSchema } from '../../validation/harvestSchema';
import toast from 'react-hot-toast';
import type { CreateHarvestPayload } from '../../types';

type HarvestLineForm = { inventory_item_id: string; store_id: string; quantity: string; uom: string; notes: string };

export default function HarvestFormPage() {
  const navigate = useNavigate();
  const createM = useCreateHarvest();
  const addLineM = useAddHarvestLine();
  const { data: cropCycles } = useCropCycles();
  const { data: landParcels } = useLandParcels();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);

  const [harvest_no, setHarvestNo] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [harvest_date, setHarvestDate] = useState(new Date().toISOString().split('T')[0]);
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<HarvestLineForm[]>([{ inventory_item_id: '', store_id: '', quantity: '', uom: '', notes: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [harvestId, setHarvestId] = useState<string | null>(null);

  const addLine = () => setLines((l) => [...l, { inventory_item_id: '', store_id: '', quantity: '', uom: '', notes: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<HarvestLineForm>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const validate = (): boolean => {
    const e: Record<string, string> = {};
    if (!crop_cycle_id) e.crop_cycle_id = 'Crop cycle is required';
    if (!harvest_date) e.harvest_date = 'Harvest date is required';
    const validLines = lines.filter((l) => l.inventory_item_id && l.store_id && parseFloat(l.quantity) > 0);
    if (validLines.length === 0) e.lines = 'Add at least one line with quantity > 0';
    setErrors(e);
    return Object.keys(e).length === 0;
  };

  const handleSubmit = async () => {
    // Prepare form data
    const validLines = lines.filter((l) => l.inventory_item_id && l.store_id && parseFloat(l.quantity) > 0);
    const formData = {
      crop_cycle_id,
      land_parcel_id: land_parcel_id || null,
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
        land_parcel_id: land_parcel_id || undefined,
        harvest_date,
        notes: notes || undefined,
      };

      const harvest = await createM.mutateAsync(payload);
      const hId = harvest.id;
      setHarvestId(hId);

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

      navigate(`/app/harvests/${hId}`);
    } catch (err) {
      // Error handled by mutation
    }
  };

  return (
    <div>
      <PageHeader
        title="New Harvest"
        backTo="/app/harvests"
        breadcrumbs={[{ label: 'Harvests', to: '/app/harvests' }, { label: 'New' }]}
      />
      <div className="bg-white rounded-lg shadow p-6 max-w-4xl">
        <div className="space-y-4">
          <FormField label="Harvest No" error={errors.harvest_no}>
            <input
              type="text"
              value={harvest_no}
              onChange={(e) => setHarvestNo(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              placeholder="Optional"
            />
          </FormField>

          <FormField label="Crop Cycle *" error={errors.crop_cycle_id}>
            <select
              value={crop_cycle_id}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="">Select crop cycle</option>
              {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </FormField>

          <FormField label="Land Parcel">
            <select
              value={land_parcel_id}
              onChange={(e) => setLandParcelId(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            >
              <option value="">None</option>
              {landParcels?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </FormField>

          <FormField label="Harvest Date *" error={errors.harvest_date}>
            <input
              type="date"
              value={harvest_date}
              onChange={(e) => setHarvestDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>

          <FormField label="Notes">
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={3}
            />
          </FormField>

          <div>
            <div className="flex justify-between items-center mb-2">
              <label className="font-medium">Lines *</label>
              <button onClick={addLine} className="text-sm text-[#1F6F5C] hover:underline">+ Add Line</button>
            </div>
            {errors.lines && <p className="text-red-600 text-sm mb-2">{errors.lines}</p>}
            <div className="space-y-2">
              {lines.map((line, idx) => (
                <div key={idx} className="flex gap-2 items-start border p-2 rounded">
                  <select
                    value={line.inventory_item_id}
                    onChange={(e) => updateLine(idx, { inventory_item_id: e.target.value })}
                    className="flex-1 px-2 py-1 border rounded text-sm"
                  >
                    <option value="">Item</option>
                    {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                  </select>
                  <select
                    value={line.store_id}
                    onChange={(e) => updateLine(idx, { store_id: e.target.value })}
                    className="flex-1 px-2 py-1 border rounded text-sm"
                  >
                    <option value="">Store</option>
                    {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                  <input
                    type="number"
                    step="0.001"
                    value={line.quantity}
                    onChange={(e) => updateLine(idx, { quantity: e.target.value })}
                    className="w-24 px-2 py-1 border rounded text-sm"
                    placeholder="Qty"
                  />
                  <input
                    type="text"
                    value={line.uom}
                    onChange={(e) => updateLine(idx, { uom: e.target.value })}
                    className="w-20 px-2 py-1 border rounded text-sm"
                    placeholder="UOM"
                  />
                  <button onClick={() => removeLine(idx)} className="text-red-600 hover:underline text-sm">Remove</button>
                </div>
              ))}
            </div>
          </div>

          <div className="flex gap-2 pt-4">
            <button
              onClick={handleSubmit}
              disabled={createM.isPending || addLineM.isPending}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {createM.isPending || addLineM.isPending ? 'Creating...' : 'Create Harvest'}
            </button>
            <button onClick={() => navigate('/app/harvests')} className="px-4 py-2 border rounded hover:bg-gray-50">
              Cancel
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
