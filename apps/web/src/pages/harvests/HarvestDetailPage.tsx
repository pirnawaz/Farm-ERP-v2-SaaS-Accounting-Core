import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useHarvest,
  useUpdateHarvest,
  useAddHarvestLine,
  useUpdateHarvestLine,
  useDeleteHarvestLine,
  usePostHarvest,
  useReverseHarvest,
} from '../../hooks/useHarvests';
import { useProjects } from '../../hooks/useProjects';
import { useInventoryStores, useInventoryItems } from '../../hooks/useInventory';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import type { UpdateHarvestPayload } from '../../types';

type HarvestLineForm = { inventory_item_id: string; store_id: string; quantity: string; uom: string; notes: string };

export default function HarvestDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: harvest, isLoading } = useHarvest(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/harvests';

  const updateM = useUpdateHarvest();
  const addLineM = useAddHarvestLine();
  const updateLineM = useUpdateHarvestLine();
  const deleteLineM = useDeleteHarvestLine();
  const postM = usePostHarvest();
  const reverseM = useReverseHarvest();
  const { data: projectsForCrop } = useProjects(harvest?.crop_cycle_id || undefined);
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();
  const { formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversalDate, setReversalDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const [harvest_no, setHarvestNo] = useState('');
  const [project_id, setProjectId] = useState('');
  const [harvest_date, setHarvestDate] = useState('');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<HarvestLineForm[]>([]);

  useEffect(() => {
    if (harvest) {
      setHarvestNo(harvest.harvest_no || '');
      setProjectId(harvest.project_id || '');
      setHarvestDate(harvest.harvest_date);
      setNotes(harvest.notes || '');
      setLines((harvest.lines || []).map((l) => ({
        inventory_item_id: l.inventory_item_id,
        store_id: l.store_id,
        quantity: String(l.quantity),
        uom: l.uom || '',
        notes: l.notes || '',
      })));
      if (!showPostModal && !showReverseModal) {
        setPostingDate(new Date().toISOString().split('T')[0]);
        setReversalDate(new Date().toISOString().split('T')[0]);
      }
    }
  }, [harvest, showPostModal, showReverseModal]);

  const isDraft = harvest?.status === 'DRAFT';
  const isPosted = harvest?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const updateLine = (i: number, f: Partial<HarvestLineForm>) => {
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));
    if (isDraft && harvest?.lines?.[i]) {
      const line = harvest.lines[i];
      const payload: any = {};
      if (f.inventory_item_id !== undefined) payload.inventory_item_id = f.inventory_item_id;
      if (f.store_id !== undefined) payload.store_id = f.store_id;
      if (f.quantity !== undefined) payload.quantity = parseFloat(f.quantity);
      if (f.uom !== undefined) payload.uom = f.uom || undefined;
      if (f.notes !== undefined) payload.notes = f.notes || undefined;
      updateLineM.mutate({ id: id!, lineId: line.id, payload });
    }
  };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const payload: UpdateHarvestPayload = {
      harvest_no: harvest_no || undefined,
      project_id: project_id || undefined,
      harvest_date,
      notes: notes || undefined,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handleAddLine = async () => {
    if (!id || !isDraft) return;
    const newLine = lines[lines.length - 1];
    if (newLine.inventory_item_id && newLine.store_id && parseFloat(newLine.quantity) > 0) {
      await addLineM.mutateAsync({
        id,
        payload: {
          inventory_item_id: newLine.inventory_item_id,
          store_id: newLine.store_id,
          quantity: parseFloat(newLine.quantity),
          uom: newLine.uom || undefined,
          notes: newLine.notes || undefined,
        },
      });
    }
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, payload: { posting_date: postingDate } });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id) return;
    await reverseM.mutateAsync({ id, payload: { reversal_date: reversalDate, reason: reverseReason || undefined } });
    setShowReverseModal(false);
    setReverseReason('');
  };

  const totalQty = (harvest?.lines || []).reduce((s, l) => s + parseFloat(String(l.quantity || 0)), 0);

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!harvest) return <div>Harvest not found.</div>;

  return (
    <div>
      <PageHeader
        title={harvest.harvest_no || `Harvest ${harvest.id.slice(0, 8)}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Harvests', to: '/app/harvests' },
          { label: harvest.harvest_no || 'Detail' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Harvest No</dt><dd className="font-medium">{harvest.harvest_no || '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Harvest Date</dt><dd>{formatDate(harvest.harvest_date)}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop Cycle</dt><dd>{harvest.crop_cycle?.name || harvest.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Project</dt><dd>{harvest.project?.name ?? '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Land Parcel</dt><dd>{harvest.land_parcel?.name || harvest.land_parcel_id || '—'}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              harvest.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              harvest.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{harvest.status}</span></dd>
          </div>
          {harvest.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${harvest.posting_group_id}`} className="text-[#1F6F5C]">{harvest.posting_group_id}</Link></dd>
            </div>
          )}
          {harvest.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(harvest.posting_date)}</dd></div>}
          {harvest.notes && <div className="md:col-span-2"><dt className="text-sm text-gray-500">Notes</dt><dd>{harvest.notes}</dd></div>}
        </dl>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h3 className="font-medium mb-4">Lines</h3>
        <table className="min-w-full border">
          <thead className="bg-[#E6ECEA]">
            <tr>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Item</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Store</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">Quantity</th>
              <th className="px-3 py-2 text-left text-xs text-gray-500">UOM</th>
              {isDraft && canEdit && <th className="w-20" />}
            </tr>
          </thead>
          <tbody>
            {harvest.lines?.map((l) => (
              <tr key={l.id}>
                <td className="px-3 py-2">{l.item?.name || l.inventory_item_id}</td>
                <td>{l.store?.name || l.store_id}</td>
                <td>{l.quantity}</td>
                <td>{l.uom || '—'}</td>
                {isDraft && canEdit && (
                  <td>
                    <button onClick={() => deleteLineM.mutate({ id: id!, lineId: l.id })} className="text-red-600 text-sm hover:underline">
                      Delete
                    </button>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
        <p className="mt-2 font-medium">Total Quantity: {totalQty.toFixed(3)}</p>
      </div>

      {isDraft && canEdit && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Harvest No">
              <input value={harvest_no} onChange={(e) => setHarvestNo(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Harvest Date">
              <input type="date" value={harvest_date} onChange={(e) => setHarvestDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select project</option>
                {(projectsForCrop ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name}</option>
                ))}
              </select>
            </FormField>
            <div className="md:col-span-2">
              <FormField label="Notes">
                <textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} />
              </FormField>
            </div>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2">
              <h4 className="font-medium">Add Line</h4>
            </div>
            <div className="flex gap-2 items-start border p-2 rounded">
              <select
                value={lines[lines.length - 1]?.inventory_item_id || ''}
                onChange={(e) => updateLine(lines.length - 1, { inventory_item_id: e.target.value })}
                className="flex-1 px-2 py-1 border rounded text-sm"
              >
                <option value="">Item</option>
                {items?.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
              </select>
              <select
                value={lines[lines.length - 1]?.store_id || ''}
                onChange={(e) => updateLine(lines.length - 1, { store_id: e.target.value })}
                className="flex-1 px-2 py-1 border rounded text-sm"
              >
                <option value="">Store</option>
                {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
              <input
                type="number"
                step="0.001"
                value={lines[lines.length - 1]?.quantity || ''}
                onChange={(e) => updateLine(lines.length - 1, { quantity: e.target.value })}
                className="w-24 px-2 py-1 border rounded text-sm"
                placeholder="Qty"
              />
              <input
                type="text"
                value={lines[lines.length - 1]?.uom || ''}
                onChange={(e) => updateLine(lines.length - 1, { uom: e.target.value })}
                className="w-20 px-2 py-1 border rounded text-sm"
                placeholder="UOM"
              />
              <button onClick={handleAddLine} className="text-[#1F6F5C] hover:underline text-sm">Add</button>
            </div>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">
              Save
            </button>
            {canPost && <button onClick={() => setShowPostModal(true)} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>}
          </div>
        </div>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Harvest">
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Harvest">
        <div className="space-y-4">
          <FormField label="Reversal Date" required>
            <input type="date" value={reversalDate} onChange={(e) => setReversalDate(e.target.value)} className="w-full px-3 py-2 border rounded" />
          </FormField>
          <FormField label="Reason">
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={3}
              placeholder="Reason for reversal"
            />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowReverseModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleReverse} disabled={reverseM.isPending} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
