import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useTransfer,
  useUpdateTransfer,
  usePostTransfer,
  useReverseTransfer,
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
import type { UpdateInvTransferPayload } from '../../types';
import { Term } from '../../components/Term';
import { term } from '../../config/terminology';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

type Line = { item_id: string; qty: string };

export default function InvTransferDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: transfer, isLoading } = useTransfer(id || '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/inventory/transfers';
  const updateM = useUpdateTransfer();
  const postM = usePostTransfer();
  const reverseM = useReverseTransfer();
  const { data: stores } = useInventoryStores();
  const { data: items } = useInventoryItems(true);
  const { data: stock } = useStockOnHand(transfer?.from_store_id ? { store_id: transfer.from_store_id } : undefined);
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  const [doc_no, setDocNo] = useState('');
  const [from_store_id, setFromStoreId] = useState('');
  const [to_store_id, setToStoreId] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);

  useEffect(() => {
    if (transfer) {
      setDocNo(transfer.doc_no);
      setFromStoreId(transfer.from_store_id);
      setToStoreId(transfer.to_store_id);
      setDocDate(transfer.doc_date);
      setLines((transfer.lines || []).map((l) => ({ item_id: l.item_id, qty: String(l.qty) })));
      if (!showPostModal && !showReverseModal) {
        const today = new Date().toISOString().split('T')[0];
        setPostingDate(today);
        setReversePostingDate(today);
      }
    }
  }, [transfer, showPostModal, showReverseModal]);

  const isDraft = transfer?.status === 'DRAFT';
  const isPosted = transfer?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const addLine = () => setLines((l) => [...l, { item_id: '', qty: '' }]);
  const removeLine = (i: number) => setLines((l) => l.filter((_, idx) => idx !== i));
  const updateLine = (i: number, f: Partial<Line>) => setLines((l) => l.map((row, idx) => (idx === i ? { ...row, ...f } : row)));

  const getAvail = (itemId: string) => {
    const r = stock?.find((s) => s.item_id === itemId);
    return r ? String(r.qty_on_hand) : '—';
  };

  const handleSave = async () => {
    if (!id || !isDraft || !canEdit) return;
    const validLines = lines.filter((l) => l.item_id && parseFloat(l.qty) > 0).map((l) => ({ item_id: l.item_id, qty: parseFloat(l.qty) }));
    if (validLines.length === 0) return;
    const payload: UpdateInvTransferPayload = { doc_no, from_store_id, to_store_id, doc_date, lines: validLines };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id || !postingDate) return;
    try {
      await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
      setShowPostModal(false);
      postM.reset();
    } catch {
      /* shown in modal */
    }
  };

  const canConfirmTransferReverse = Boolean(id && reversePostingDate && reverseReason.trim());

  const handleReverse = async () => {
    if (!canConfirmTransferReverse) return;
    try {
      await reverseM.mutateAsync({ id: id!, payload: { posting_date: reversePostingDate, reason: reverseReason } });
      setShowReverseModal(false);
      setReverseReason('');
      reverseM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!transfer) return <div>Stock transfer not found.</div>;

  const lineTotal = (l: { qty: string; unit_cost_snapshot?: string }) => (parseFloat(String(l.qty)) || 0) * (parseFloat(String(l.unit_cost_snapshot)) || 0);
  const total = (transfer.lines || []).reduce((a, l) => a + lineTotal(l), 0);

  return (
    <div className="space-y-6">
      <PageHeader
        title={`${term('transferSingular')} ${transfer.doc_no}`}
        description="Move stock between your stores without a supplier receipt or crop issue."
        helper="Transfers reallocate quantity from one store to another when posted—not a goods received or stock used."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('transfer'), to: '/app/inventory/transfers' },
          { label: transfer.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{transfer.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">From Store</dt><dd className="font-medium">{transfer.from_store?.name || transfer.from_store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">To Store</dt><dd className="font-medium">{transfer.to_store?.name || transfer.to_store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Doc date</dt><dd className="tabular-nums">{formatDate(transfer.doc_date, { variant: 'medium' })}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><PostingStatusBadge status={transfer.status} /></dd>
          </div>
          {transfer.posting_group_id && (
            <div className="md:col-span-2"><dt className="text-sm text-gray-500"><Term k="postingGroup" showHint /></dt><dd><Link to={`/app/posting-groups/${transfer.posting_group_id}`} className="text-[#1F6F5C]">{transfer.posting_group_id}</Link></dd></div>
          )}
          {transfer.posting_date && <div><dt className="text-sm text-gray-500">Posting date</dt><dd className="tabular-nums">{formatDate(transfer.posting_date, { variant: 'medium' })}</dd></div>}
        </dl>
      </div>

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-4">Edit draft</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Doc Date"><input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="From Store">
              <select value={from_store_id} onChange={(e) => setFromStoreId(e.target.value)} className="w-full px-3 py-2 border rounded">{stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
            </FormField>
            <FormField label="To Store">
              <select value={to_store_id} onChange={(e) => setToStoreId(e.target.value)} className="w-full px-3 py-2 border rounded">{stores?.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>
            </FormField>
          </div>
          <div className="mb-4">
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines</h4><button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <div className="overflow-x-auto">
            <table className="min-w-full border">
              <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Available</th><th className="w-10" /></tr></thead>
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
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && (
              <button
                type="button"
                onClick={() => {
                  postM.reset();
                  setShowPostModal(true);
                }}
                className="px-4 py-2 bg-green-600 text-white rounded min-h-[44px]"
              >
                Record to accounts
              </button>
            )}
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-2">Lines</h3>
          <div className="overflow-x-auto">
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
            <tbody>
              {(transfer.lines || []).map((l) => (
                <tr key={l.id}><td className="px-3 py-2">{formatItemDisplayName(l.item)}</td><td>{l.qty}</td><td>{l.line_total ? <span className="tabular-nums">{formatMoney(parseFloat(String(l.line_total)))}</span> : '—'}</td></tr>
              ))}
            </tbody>
          </table>
          </div>
          <p className="mt-2 font-medium">Total: <span className="tabular-nums">{formatMoney(total)}</span></p>
        </div>
      )}

      {isPosted && canPost && (
        <div className="mb-6">
          <button
            type="button"
            onClick={() => {
              reverseM.reset();
              setShowReverseModal(true);
            }}
            className="px-4 py-2 bg-red-600 text-white rounded min-h-[44px]"
          >
            {term('reverseAction')}
          </button>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          postM.reset();
        }}
        title={`Record to accounts: ${term('transfer')}`}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This will move stock quantities between your stores for the posting date below. Cancel if you need to adjust the draft first.
          </p>
          <PrePostChecklist
            items={[{ ok: Boolean(postingDate), label: 'Posting date chosen' }]}
            blockingHint={!postingDate ? 'Choose a posting date before recording.' : undefined}
          />
          <OperatorErrorCallout error={postM.isError ? formatOperatorError(postM.error) : null} />
          <FormField label="Posting date" required>
            <input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded min-h-[44px]" />
          </FormField>
          <FormField label="Idempotency Key"><input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" /></FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowPostModal(false);
                postM.reset();
              }}
              className="px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending || !postingDate}
              className="px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {postM.isPending ? term('postActionPending') : 'Confirm'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setReverseReason('');
          reverseM.reset();
        }}
        title={`${term('reverseAction')}: ${term('transfer')}`}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This creates offsetting stock movements as of the posting date below. Cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(reversePostingDate), label: 'Posting date chosen' },
              { ok: Boolean(reverseReason.trim()), label: 'Reason entered' },
            ]}
            blockingHint={!canConfirmTransferReverse ? 'Choose a posting date and enter a reason before reversing.' : undefined}
          />
          <OperatorErrorCallout error={reverseM.isError ? formatOperatorError(reverseM.error) : null} />
          <FormField label="Posting date" required>
            <input
              type="date"
              value={reversePostingDate}
              onChange={(e) => setReversePostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <FormField label="Reason" required>
            <textarea value={reverseReason} onChange={(e) => setReverseReason(e.target.value)} className="w-full px-3 py-2 border rounded" rows={2} />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowReverseModal(false);
                setReverseReason('');
                reverseM.reset();
              }}
              className="px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={!canConfirmTransferReverse || reverseM.isPending}
              className="px-4 py-2 bg-red-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {reverseM.isPending ? term('reverseActionPending') : 'Confirm reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
