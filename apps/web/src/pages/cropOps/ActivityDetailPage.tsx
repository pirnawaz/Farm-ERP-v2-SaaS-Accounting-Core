import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useActivity,
  useUpdateActivity,
  usePostActivity,
  useReverseActivity,
  useActivityTypes,
} from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { useWorkers } from '../../hooks/useLabour';
import { useInventoryStores, useInventoryItems, useStockOnHand } from '../../hooks/useInventory';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateCropActivityPayload } from '../../types';

type InputLine = { store_id: string; item_id: string; qty: string };
type LabourLine = { worker_id: string; rate_basis: string; units: string; rate: string };

export default function ActivityDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: activity, isLoading } = useActivity(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/crop-ops/activities';

  const updateM = useUpdateActivity();
  const postM = usePostActivity();
  const reverseM = useReverseActivity();
  const { data: activityTypes } = useActivityTypes();
  const { data: cropCycles } = useCropCycles();
  const { data: workers } = useWorkers();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand({});
  const { hasRole } = useRole();
  const { formatMoney } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(() => uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [activity_type_id, setActivityTypeId] = useState('');
  const [activity_date, setActivityDate] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [land_parcel_id, setLandParcelId] = useState('');
  const [notes, setNotes] = useState('');
  const [inputs, setInputs] = useState<InputLine[]>([]);
  const [labour, setLabour] = useState<LabourLine[]>([]);

  const { data: projectsForCrop } = useProjects(crop_cycle_id || activity?.crop_cycle_id);
  const { data: landParcels } = useLandParcels();

  useEffect(() => {
    if (activity) {
      setDocNo(activity.doc_no);
      setActivityTypeId(activity.activity_type_id);
      setActivityDate(activity.activity_date);
      setCropCycleId(activity.crop_cycle_id);
      setProjectId(activity.project_id);
      setLandParcelId(activity.land_parcel_id || '');
      setNotes(activity.notes || '');
      setInputs((activity.inputs || []).map((l) => ({ store_id: l.store_id, item_id: l.item_id, qty: String(l.qty) })));
      setLabour((activity.labour || []).map((l) => ({
        worker_id: l.worker_id,
        rate_basis: l.rate_basis || 'DAILY',
        units: String(l.units),
        rate: String(l.rate),
      })));
      if (!showPostModal && !showReverseModal) setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [activity, showPostModal, showReverseModal]);

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

  const isDraft = activity?.status === 'DRAFT';
  const isPosted = activity?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const inputsCost = (activity?.inputs || []).reduce((s, i) => s + parseFloat(String(i.line_total || 0)), 0);
  const labourCost = (activity?.labour || []).reduce((s, l) => s + parseFloat(String(l.amount || 0)), 0);
  const totalCost = inputsCost + labourCost;

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
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
    const payload: UpdateCropActivityPayload = {
      doc_no,
      activity_type_id,
      activity_date,
      crop_cycle_id,
      project_id,
      land_parcel_id: land_parcel_id || undefined,
      notes: notes || undefined,
      inputs: validInputs,
      labour: validLabour,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id) return;
    await reverseM.mutateAsync({ id, payload: { posting_date: postingDate, reason: reverseReason || undefined } });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!activity) return <div>Activity not found.</div>;

  return (
    <div>
      <PageHeader
        title={activity.doc_no}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Activities', to: '/app/crop-ops/activities' },
          { label: activity.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{activity.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Type</dt><dd>{activity.type?.name || activity.activity_type_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Activity Date</dt><dd>{formatDate(activity.activity_date)}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop Cycle</dt><dd>{activity.crop_cycle?.name || activity.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Project</dt><dd>{activity.project?.name || activity.project_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Land Parcel</dt><dd>{activity.land_parcel?.name || activity.land_parcel_id || '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              activity.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              activity.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{activity.status}</span></dd>
          </div>
          {activity.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${activity.posting_group_id}`} className="text-[#1F6F5C]">{activity.posting_group_id}</Link></dd>
            </div>
          )}
          {activity.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(activity.posting_date)}</dd></div>}
          {(isPosted || activity.status === 'REVERSED') && (
            <>
              <div><dt className="text-sm text-gray-500">Inputs cost</dt><dd><span className="tabular-nums">{formatMoney(inputsCost)}</span></dd></div>
              <div><dt className="text-sm text-gray-500">Labour cost</dt><dd><span className="tabular-nums">{formatMoney(labourCost)}</span></dd></div>
              <div><dt className="text-sm text-gray-500">Total cost</dt><dd className="font-medium"><span className="tabular-nums">{formatMoney(totalCost)}</span></dd></div>
            </>
          )}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Activity Date"><input type="date" value={activity_date} onChange={(e) => setActivityDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Type">
              <select value={activity_type_id} onChange={(e) => setActivityTypeId(e.target.value)} className="w-full px-3 py-2 border rounded">
                {activityTypes?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </FormField>
            <FormField label="Crop Cycle">
              <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
                {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select</option>
                {(projectsForCrop || []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </FormField>
            <FormField label="Land Parcel">
              <select value={land_parcel_id} onChange={(e) => setLandParcelId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">None</option>
                {landParcels?.map((p) => <option key={p.id} value={p.id}>{p.name || p.id}</option>)}
              </select>
            </FormField>
            <div className="md:col-span-2">
              <FormField label="Notes"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} /></FormField>
            </div>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Inputs</h4><button type="button" onClick={addInput} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr><th className="px-3 py-2 text-left text-xs text-gray-500">Store</th><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Available</th><th className="w-10" /></tr>
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
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.qty} onChange={(e) => updateInput(i, { qty: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td className="px-3 py-2 text-sm">{getAvail(line.store_id, line.item_id)}</td>
                    <td><button type="button" onClick={() => removeInput(i)} className="text-red-600 text-sm">Del</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Labour</h4><button type="button" onClick={addLabour} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]">
                <tr><th className="px-3 py-2 text-left text-xs text-gray-500">Worker</th><th className="px-3 py-2 text-left text-xs text-gray-500">Basis</th><th className="px-3 py-2 text-left text-xs text-gray-500">Units</th><th className="px-3 py-2 text-left text-xs text-gray-500">Rate</th><th className="w-10" /></tr>
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
                        <option value="DAILY">DAILY</option><option value="HOURLY">HOURLY</option><option value="PIECE">PIECE</option>
                      </select>
                    </td>
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.units} onChange={(e) => updateLabour(i, { units: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.rate} onChange={(e) => updateLabour(i, { rate: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td><button type="button" onClick={() => removeLabour(i)} className="text-red-600 text-sm">Del</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && <button onClick={() => setShowPostModal(true)} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>}
          </div>
        </div>
      ) : (
        <>
          <div className="bg-white rounded-lg shadow p-6 mb-6">
            <h3 className="font-medium mb-2">Inputs</h3>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Store</th><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
              <tbody>
                {(activity.inputs || []).map((l) => (
                  <tr key={l.id}>
                    <td className="px-3 py-2">{l.store?.name}</td>
                    <td>{l.item?.name}</td>
                    <td>{l.qty}</td>
                    <td>{l.unit_cost_snapshot != null ? <span className="tabular-nums">{formatMoney(l.unit_cost_snapshot)}</span> : '—'}</td>
                    <td>{l.line_total != null ? <span className="tabular-nums">{formatMoney(l.line_total)}</span> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {activity.inputs && activity.inputs.some((l) => l.line_total) && (
              <p className="mt-2 font-medium">Inputs total: <span className="tabular-nums">{formatMoney(inputsCost)}</span></p>
            )}
          </div>
          <div className="bg-white rounded-lg shadow p-6 mb-6">
            <h3 className="font-medium mb-2">Labour</h3>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Worker</th><th className="px-3 py-2 text-left text-xs text-gray-500">Units</th><th className="px-3 py-2 text-right text-xs text-gray-500">Rate</th><th className="px-3 py-2 text-right text-xs text-gray-500">Amount</th></tr></thead>
              <tbody>
                {(activity.labour || []).map((l) => (
                  <tr key={l.id}>
                    <td className="px-3 py-2">{l.worker?.name}</td>
                    <td>{l.units}</td>
                    <td className="text-right"><span className="tabular-nums">{formatMoney(l.rate)}</span></td>
                    <td className="text-right">{l.amount != null ? <span className="tabular-nums">{formatMoney(l.amount)}</span> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {activity.labour && activity.labour.some((l) => l.amount) && (
              <p className="mt-2 font-medium">Labour total: <span className="tabular-nums">{formatMoney(labourCost)}</span></p>
            )}
          </div>
        </>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Activity">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Activity">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Reason"><textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} placeholder="Optional" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowReverseModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleReverse} disabled={reverseM.isPending} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
