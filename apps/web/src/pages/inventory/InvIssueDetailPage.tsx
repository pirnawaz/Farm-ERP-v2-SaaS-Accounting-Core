import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useIssue,
  useUpdateIssue,
  usePostIssue,
  useReverseIssue,
  useInventoryStores,
  useInventoryItems,
  useStockOnHand,
} from '../../hooks/useInventory';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import type { UpdateInvIssuePayload } from '../../types';

type Line = { item_id: string; qty: string };

export default function InvIssueDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: issue, isLoading } = useIssue(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/inventory/issues';
  const updateM = useUpdateIssue();
  const postM = usePostIssue();
  const reverseM = useReverseIssue();
  const { data: cropCycles } = useCropCycles();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand(issue?.store_id ? { store_id: issue.store_id } : undefined);
  const { hasRole } = useRole();
  const { formatMoney } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [store_id, setStoreId] = useState('');
  const [crop_cycle_id, setCropCycleId] = useState('');
  const [project_id, setProjectId] = useState('');
  const [activity_id, setActivityId] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);

  const { data: projectsForCrop } = useProjects(crop_cycle_id || issue?.crop_cycle_id);

  useEffect(() => {
    if (issue) {
      setDocNo(issue.doc_no);
      setStoreId(issue.store_id);
      setCropCycleId(issue.crop_cycle_id);
      setProjectId(issue.project_id);
      setActivityId(issue.activity_id || '');
      setDocDate(issue.doc_date);
      setLines((issue.lines || []).map((l) => ({ item_id: l.item_id, qty: String(l.qty) })));
      if (!showPostModal && !showReverseModal) setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [issue, showPostModal, showReverseModal]);

  const isDraft = issue?.status === 'DRAFT';
  const isPosted = issue?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) =>
    setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getAvail = (itemId: string) => {
    const r = stock?.find((s) => s.item_id === itemId);
    return r ? String(r.qty_on_hand) : '—';
  };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const validLines = lines
      .filter((l) => l.item_id && parseFloat(l.qty) > 0)
      .map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    if (validLines.length === 0) return;
    const payload: UpdateInvIssuePayload = {
      doc_no,
      store_id,
      crop_cycle_id,
      project_id,
      activity_id: activity_id || undefined,
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
  if (!issue) return <div>Issue not found.</div>;

  return (
    <div>
      <PageHeader
        title={`Issue ${issue.doc_no}`}
        backTo={backTo}
        breadcrumbs={[
          { label: 'Inventory', to: '/app/inventory' },
          { label: 'Issues', to: '/app/inventory/issues' },
          { label: issue.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{issue.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Store</dt><dd>{issue.store?.name || issue.store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Crop Cycle</dt><dd>{issue.crop_cycle?.name || issue.crop_cycle_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Project</dt><dd>{issue.project?.name || issue.project_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Doc Date</dt><dd>{issue.doc_date}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><span className={`px-2 py-1 rounded text-xs ${
              issue.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
              issue.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
            }`}>{issue.status}</span></dd>
          </div>
          {issue.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting Group</dt>
              <dd><Link to={`/app/posting-groups/${issue.posting_group_id}`} className="text-blue-600">{issue.posting_group_id}</Link></dd>
            </div>
          )}
          {issue.posting_date && <div><dt className="text-sm text-gray-500">Posting Date</dt><dd>{issue.posting_date}</dd></div>}
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
            <FormField label="Crop Cycle">
              <select value={crop_cycle_id} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="w-full px-3 py-2 border rounded">
                {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
            </FormField>
            <FormField label="Project">
              <select value={project_id} onChange={(e) => setProjectId(e.target.value)} className="w-full px-3 py-2 border rounded">
                <option value="">Select</option>
                {(projectsForCrop || [])?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
              </select>
            </FormField>
            <FormField label="Activity"><input value={activity_id} onChange={(e) => setActivityId(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines</h4><button type="button" onClick={addLine} className="text-sm text-blue-600">+ Add</button></div>
            <table className="min-w-full border">
              <thead className="bg-gray-50">
                <tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Available</th><th className="w-10" /></tr>
              </thead>
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
                    <td className="px-3 py-2 text-sm">{getAvail(line.item_id)}</td>
                    <td><button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Del</button></td>
                  </tr>
                ))}
              </tbody>
            </table>
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
              {(issue.lines || []).map((l) => (
                <tr key={l.id}>
                  <td className="px-3 py-2">{l.item?.name}</td>
                  <td>{l.qty}</td>
                  <td>{l.unit_cost_snapshot != null ? formatMoney(l.unit_cost_snapshot) : '—'}</td>
                  <td>{l.line_total != null ? formatMoney(l.line_total) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {issue.lines && issue.lines.some((l) => l.line_total) && (
            <p className="mt-2 font-medium">Total: {formatMoney((issue.lines || []).reduce((a, l) => a + parseFloat(String(l.line_total || 0)), 0))}</p>
          )}
        </div>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button onClick={() => setShowReverseModal(true)} className="px-4 py-2 bg-red-600 text-white rounded">Reverse</button>
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title="Post Issue">
        <div className="space-y-4">
          <FormField label="Posting Date" required><input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex gap-2 pt-4">
            <button onClick={() => setShowPostModal(false)} className="px-4 py-2 border rounded">Cancel</button>
            <button onClick={handlePost} disabled={postM.isPending} className="px-4 py-2 bg-green-600 text-white rounded">Post</button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title="Reverse Issue">
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
