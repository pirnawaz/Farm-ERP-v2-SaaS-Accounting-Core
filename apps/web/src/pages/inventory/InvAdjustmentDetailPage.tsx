import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useAdjustment,
  useUpdateAdjustment,
  usePostAdjustment,
  useReverseAdjustment,
  useInventoryStores,
  useInventoryItems,
  useStockOnHand,
} from '../../hooks/useInventory';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateInvAdjustmentPayload, InvAdjustmentReason } from '../../types';

const REASONS: InvAdjustmentReason[] = ['LOSS', 'DAMAGE', 'COUNT_GAIN', 'COUNT_LOSS', 'OTHER'];

type Line = { item_id: string; qty_delta: string };

export default function InvAdjustmentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: adj, isLoading } = useAdjustment(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/inventory/adjustments';
  const updateM = useUpdateAdjustment();
  const postM = usePostAdjustment();
  const reverseM = useReverseAdjustment();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand(adj?.store_id ? { store_id: adj.store_id } : undefined);
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [reason, setReason] = useState<InvAdjustmentReason>('LOSS');
  const [notes, setNotes] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);

  useEffect(() => {
    if (adj) {
      setDocNo(adj.doc_no);
      setStoreId(adj.store_id);
      setReason(adj.reason);
      setNotes(adj.notes || '');
      setDocDate(adj.doc_date);
      setLines((adj.lines || []).map((l) => ({ item_id: l.item_id, qty_delta: String(l.qty_delta) })));
      if (!showPostModal && !showReverseModal) setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [adj, showPostModal, showReverseModal]);

  const isDraft = adj?.status === 'DRAFT';
  const isPosted = adj?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty_delta: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) => setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getAvail = (itemId: string) => { const r = stock?.find((s) => s.item_id === itemId); return r ? String(r.qty_on_hand) : '—'; };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const validLines = lines.filter((l) => l.item_id && parseFloat(l.qty_delta) !== 0).map((l) => ({ item_id: l.item_id, qty_delta: parseFloat(l.qty_delta) }));
    if (validLines.length === 0) return;
    const payload: UpdateInvAdjustmentPayload = { doc_no, store_id, reason, notes: notes || undefined, doc_date, lines: validLines };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id || !reverseReason.trim()) return;
    await reverseM.mutateAsync({ id, payload: { posting_date: postingDate, reason: reverseReason } });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!adj) return <div>Adjustment not found.</div>;

  const lineTotal = (l: { qty_delta: string; line_total?: string }) => (l.line_total ? parseFloat(String(l.line_total)) : 0);
  const total = (adj.lines || []).reduce((a, l) => a + lineTotal(l), 0);

  return (
    <div>
      <PageHeader
        title={`Adjustment ${adj.doc_no}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'Adjustments', to: '/app/inventory/adjustments' },
          { label: adj.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{adj.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Store</dt><dd className="font-medium">{adj.store?.name || adj.store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Reason</dt><dd>{adj.reason}</dd></div>
          <div><dt className="text-sm text-gray-500">Doc Date</dt><dd>{formatDate(adj.doc_date)}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${adj.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : adj.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'}`}>{adj.status}</span></dd>
          </div>
          {adj.notes && <div className="md:col-span-2"><dt className="text-sm text-gray-500">Notes</dt><dd>{adj.notes}</dd></div>}
          {adj.posting_group_id && <div className="md:col-span-2"><dt className="text-sm text-gray-500">Posting Group</dt><dd><Link to={`/app/posting-groups/${adj.posting_group_id}`} className="text-[#1F6F5C]">{adj.posting_group_id}</Link></dd></div>}
          {adj.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{formatDate(adj.posting_date)}</dd></div>}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Doc Date"><input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Store"><select value={store_id} onChange={(e) => setStoreId(e.target.value)} className="w-full px-3 py-2 border rounded">{stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></FormField>
            <FormField label="Reason"><select value={reason} onChange={(e) => setReason(e.target.value as InvAdjustmentReason)} className="w-full px-3 py-2 border rounded">{REASONS.map((r) => <option key={r} value={r}>{r}</option>)}</select></FormField>
            <div className="md:col-span-2"><FormField label="Notes"><textarea value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} /></FormField></div>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines (qty_delta: + gain, - loss)</h4><button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty delta</th><th className="px-3 py-2 text-left text-xs text-gray-500">Available</th><th className="w-10" /></tr></thead>
              <tbody>
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2"><select value={line.item_id} onChange={(e) => updateLine(i, { item_id: e.target.value })} className="w-full px-2 py-1 border rounded text-sm"><option value="">Select</option>{items?.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}</select></td>
                    <td className="px-3 py-2"><input type="number" step="any" value={line.qty_delta} onChange={(e) => updateLine(i, { qty_delta: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" placeholder="-2 or 3" /></td>
                    <td className="px-3 py-2 text-sm">{getAvail(line.item_id)}</td>
                    <td><button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Del</button></td>
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
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-2">Lines</h3>
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty delta</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
            <tbody>
              {(adj.lines || []).map((l) => (
                <tr key={l.id}><td className="px-3 py-2">{l.item?.name}</td><td>{l.qty_delta}</td><td>{l.line_total ? <span className="tabular-nums">{formatMoney(parseFloat(String(l.line_total)))}</span> : '—'}</td></tr>
              ))}
            </tbody>
          </table>
          <p className="mt-2 font-medium">Total: <span className="tabular-nums">{formatMoney(total)}</span></p>
        </div>
      )}

      {isPosted && canPost && <div className="mb-6"><button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button></div>}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Adjustment">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Adjustment">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Reason" required><textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowReverseModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handleReverse} disabled={!reverseReason.trim() || reverseM.isPending} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
