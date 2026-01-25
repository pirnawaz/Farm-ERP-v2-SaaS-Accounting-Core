import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useGRN,
  useUpdateGRN,
  usePostGRN,
  useReverseGRN,
  useInventoryStores,
  useInventoryItems,
} from '../../hooks/useInventory';
import { useParties } from '../../hooks/useParties';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateInvGrnPayload } from '../../types';

type Line = { item_id: string; qty: string; unit_cost: string };

export default function InvGrnDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: grn, isLoading } = useGRN(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/inventory/grns';
  const updateM = useUpdateGRN();
  const postM = usePostGRN();
  const reverseM = useReverseGRN();
  const { data: parties } = useParties();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { hasRole } = useRole();
  const { formatMoney } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  // Editable form (DRAFT only)
  const [doc_no, setDocNo] = useState('');
  const [supplier_party_id, setSupplierPartyId] = useState('');
  const [store_id, setStoreId] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);

  useEffect(() => {
    if (grn) {
      setDocNo(grn.doc_no);
      setSupplierPartyId(grn.supplier_party_id || '');
      setStoreId(grn.store_id);
      setDocDate(grn.doc_date);
      setLines(
        (grn.lines || []).map((l) => ({
          item_id: l.item_id,
          qty: String(l.qty),
          unit_cost: String(l.unit_cost),
        }))
      );
      if (!showPostModal && !showReverseModal) {
        setPostingDate(new Date().toISOString().split('T')[0]);
      }
    }
  }, [grn, showPostModal, showReverseModal]);

  const isDraft = grn?.status === 'DRAFT';
  const isPosted = grn?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '', unit_cost: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0 && parseFloat(l.unit_cost) >= 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty), unit_cost: parseFloat(l.unit_cost) }));
    if (validLines.length === 0) return;
    const payload: UpdateInvGrnPayload = {
      doc_no,
      supplier_party_id: supplier_party_id || undefined,
      store_id,
      doc_date,
      lines: validLines,
    };
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
  if (!grn) return <div>GRN not found.</div>;

  const lineTotals = (grn.lines || []).map((l) => parseFloat(String(l.qty)) * parseFloat(String(l.unit_cost)));
  const total = lineTotals.reduce((a, b) => a + b, 0);

  return (
    <div>
      <PageHeader
        title={`GRN ${grn.doc_no}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'GRNs', to: '/app/inventory/grns' },
          { label: grn.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{grn.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Store</dt><dd className="font-medium">{grn.store?.name || grn.store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Doc Date</dt><dd>{grn.doc_date}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              grn.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              grn.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{grn.status}</span></dd>
          </div>
          {grn.supplier && <div><dt className="text-sm text-gray-500">Supplier</dt><dd>{grn.supplier.name}</dd></div>}
          {grn.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${grn.posting_group_id}`} className="text-blue-600">{grn.posting_group_id}</Link></dd>
            </div>
          )}
          {grn.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{grn.posting_date}</dd></div>}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-4">Edit (DRAFT)</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Doc Date"><input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Store">
              <select value={store_id} onChange={(e) => setStoreId(e.target.value)} className="w-full px-3 py-2 border rounded">
                {stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </FormField>
            <FormField label="Supplier">
              <select value={supplier_party_id} onChange={(e) => setSupplierPartyId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">—</option>
                {parties?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </FormField>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines</h4><button type="button" onClick={addLine} className="text-sm text-blue-600">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-gray-50"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th><th className="w-10" /></tr></thead>
              <tbody>
                {lines.map((line, i) => (
                  <tr key={i}>
                    <td className="px-3 py-2">
                      <select value={line.item_id} onChange={(e) => updateLine(i, { item_id: e.target.value })} className="w-full px-2 py-1 border rounded text-sm">
                        <option value="">Select</option>
                        {items?.map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}
                      </select>
                    </td>
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.qty} onChange={(e) => updateLine(i, { qty: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td className="px-3 py-2"><input type="number" step="any" min="0" value={line.unit_cost} onChange={(e) => updateLine(i, { unit_cost: e.target.value })} className="w-24 px-2 py-1 border rounded text-sm" /></td>
                    <td className="px-3 py-2 text-sm">{formatMoney((parseFloat(line.qty) || 0) * (parseFloat(line.unit_cost) || 0))}</td>
                    <td><button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Del</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="mt-2 text-sm font-medium">Total: {formatMoney(lines.reduce((a, l) => a + (parseFloat(l.qty) || 0) * (parseFloat(l.unit_cost) || 0), 0))}</p>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
            {canPost && <button onClick={() => setShowPostModal(true)} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>}
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-2">Lines</h3>
          <table className="min-w-full border">
            <thead className="bg-gray-50"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
            <tbody>
              {(grn.lines || []).map((l) => (
                <tr key={l.id}><td className="px-3 py-2">{l.item?.name}</td><td>{l.qty}</td><td>{l.unit_cost}</td><td>{formatMoney(parseFloat(String(l.line_total)))}</td></tr>
              ))}
            </tbody>
          </table>
          <p className="mt-2 font-medium">Total: {formatMoney(total)}</p>
        </div>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post GRN">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse GRN">
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
