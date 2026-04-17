import { useState, useEffect, useMemo } from 'react';
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
import { Term } from '../../components/Term';
import { term } from '../../config/terminology';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';
import toast from 'react-hot-toast';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

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
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());
  const [reverseReason, setReverseReason] = useState('');

  // Editable form (DRAFT only)
  const [doc_no, setDocNo] = useState('');
  const [supplier_party_id, setSupplierPartyId] = useState('');
  const [store_id, setStoreId] = useState('');
  const [doc_date, setDocDate] = useState('');
  const [lines, setLines] = useState<Line[]>([]);

  /** Normalize API date to YYYY-MM-DD for input[type=date]. */
  const toDateOnly = (d: string | undefined): string => (d ? String(d).slice(0, 10) : '');

  useEffect(() => {
    if (grn) {
      setDocNo(grn.doc_no);
      setSupplierPartyId(grn.supplier_party_id || '');
      setStoreId(grn.store_id);
      setDocDate(toDateOnly(grn.doc_date));
      setLines(
        (grn.lines || []).map((l) => ({
          item_id: l.item_id,
          qty: String(l.qty),
          unit_cost: String(l.unit_cost),
        }))
      );
      if (!showPostModal && !showReverseModal) {
        setPostingDate(new Date().toISOString().split('T')[0]);
        setReversePostingDate(new Date().toISOString().split('T')[0]);
      }
    }
  }, [grn, showPostModal, showReverseModal]);

  const isDraft = grn?.status === 'DRAFT';
  const isPosted = grn?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const validDraftLineCount = useMemo(
    () => lines.filter((l) => l.item_id && parseFloat(l.qty) > 0 && parseFloat(l.unit_cost) >= 0).length,
    [lines]
  );
  const docDateReady = Boolean(doc_date || toDateOnly(grn?.doc_date));
  const grnDraftReadyForRecord = Boolean(isDraft && store_id && docDateReady && validDraftLineCount > 0);
  const canConfirmRecord = Boolean(postingDate && grnDraftReadyForRecord);

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
    const docDateValue = doc_date || toDateOnly(grn?.doc_date);
    if (!docDateValue) {
      toast.error('Doc date is required');
      return;
    }
    const payload: UpdateInvGrnPayload = {
      doc_no,
      supplier_party_id: supplier_party_id || undefined,
      store_id,
      doc_date: docDateValue,
      lines: validLines,
    };
    await updateM.mutateAsync({ id, payload });
  };

  const handlePost = async () => {
    if (!id) return;
    try {
      await postM.mutateAsync({ id, payload: { posting_date: postingDate, idempotency_key: idempotencyKey } });
      setShowPostModal(false);
      postM.reset();
    } catch {
      /* Error shown in modal via OperatorErrorCallout */
    }
  };

  const canConfirmGrnReverse = Boolean(reversePostingDate && reverseReason.trim());

  const handleReverse = async () => {
    if (!id || !canConfirmGrnReverse) return;
    try {
      await reverseM.mutateAsync({ id, payload: { posting_date: reversePostingDate, reason: reverseReason } });
      setShowReverseModal(false);
      setReverseReason('');
      reverseM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;
  if (!grn) return <div>Goods received record not found.</div>;

  const lineTotals = (grn.lines || []).map((l) => parseFloat(String(l.qty)) * parseFloat(String(l.unit_cost)));
  const total = lineTotals.reduce((a, b) => a + b, 0);

  return (
    <div className="space-y-6">
      <PageHeader
        title={`${term('grnSingular')} ${grn.doc_no}`}
        description="Receipt of stock into a store from a supplier or inbound movement."
        helper="Goods received increases on-hand quantity when posted—not a transfer between your stores."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Inventory Overview', to: '/app/inventory' },
          { label: term('grn'), to: '/app/inventory/grns' },
          { label: grn.doc_no },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div><dt className="text-sm text-gray-500">Doc No</dt><dd className="font-medium">{grn.doc_no}</dd></div>
          <div><dt className="text-sm text-gray-500">Store</dt><dd className="font-medium">{grn.store?.name || grn.store_id}</dd></div>
          <div><dt className="text-sm text-gray-500">Doc date</dt><dd className="tabular-nums">{formatDate(grn.doc_date, { variant: 'medium' })}</dd></div>
          <div><dt className="text-sm text-gray-500">Status</dt>
            <dd><PostingStatusBadge status={grn.status} /></dd>
          </div>
          {grn.supplier && <div><dt className="text-sm text-gray-500">Supplier</dt><dd>{grn.supplier.name}</dd></div>}
          {grn.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500"><Term k="postingGroup" showHint /></dt>
              <dd><Link to={`/app/posting-groups/${grn.posting_group_id}`} className="text-[#1F6F5C]">{grn.posting_group_id}</Link></dd>
            </div>
          )}
          {grn.posting_date && <div><dt className="text-sm text-gray-500">Posting date</dt><dd className="tabular-nums">{formatDate(grn.posting_date, { variant: 'medium' })}</dd></div>}
        </dl>
      </div>

      {grn.ap_match_summary && grn.status === 'POSTED' && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="font-medium mb-2">Linked supplier bills (AP matching)</h3>
          <p className="text-sm text-gray-600 mb-3">
            Matched value:{' '}
            <span className="font-semibold tabular-nums">{formatMoney(String(grn.ap_match_summary.matched_amount))}</span>
            {' · '}
            Unmatched receipt value:{' '}
            <span className="font-semibold tabular-nums">
              {formatMoney(String(grn.ap_match_summary.unmatched_receipt_value))}
            </span>
          </p>
          {grn.ap_match_summary.matched_bills?.length ? (
            <ul className="text-sm space-y-1">
              {grn.ap_match_summary.matched_bills.map((b) => (
                <li key={b.supplier_invoice_id}>
                  <Link
                    to={`/app/accounting/supplier-invoices/${b.supplier_invoice_id}`}
                    className="text-[#1F6F5C] hover:underline"
                  >
                    {b.reference_no || b.supplier_invoice_id}
                  </Link>
                  <span className="text-gray-500"> — matched </span>
                  <span className="tabular-nums font-medium">{formatMoney(b.matched_amount)}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-gray-500">No supplier bill lines matched to this receipt yet.</p>
          )}
        </div>
      )}

      {isDraft && canEdit ? (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h3 className="text-sm font-semibold text-gray-900 mb-4">Edit draft</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <FormField label="Doc No"><input value={doc_no} onChange={(e) => setDocNo(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
            <FormField label="Doc Date" required><input type="date" value={doc_date} onChange={(e) => setDocDate(e.target.value)} className="w-full px-3 py-2 border rounded" /></FormField>
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
            <div className="flex justify-between mb-2"><h4 className="font-medium">Lines</h4><button type="button" onClick={addLine} className="text-sm text-[#1F6F5C]">+ Add</button></div>
            <div className="overflow-x-auto">
              <table className="min-w-full border">
                <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th><th className="w-10" /></tr></thead>
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
                      <td className="px-3 py-2 text-sm"><span className="tabular-nums">{formatMoney((parseFloat(line.qty) || 0) * (parseFloat(line.unit_cost) || 0))}</span></td>
                      <td><button type="button" onClick={() => removeLine(i)} className="text-red-600 text-sm">Del</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-2 text-sm font-medium">Total: <span className="tabular-nums">{formatMoney(lines.reduce((a, l) => a + (parseFloat(l.qty) || 0) * (parseFloat(l.unit_cost) || 0), 0))}</span></p>
          </div>
          <div className="flex gap-2">
            <button onClick={handleSave} disabled={updateM.isPending || !(doc_date || toDateOnly(grn?.doc_date))} className="px-4 py-2 bg-[#1F6F5C] text-white rounded">Save</button>
            {canPost && (
              <button
                type="button"
                onClick={() => {
                  postM.reset();
                  setShowPostModal(true);
                }}
                disabled={!grnDraftReadyForRecord}
                title={
                  !grnDraftReadyForRecord
                    ? 'Complete store, document date, and at least one valid line before recording to accounts.'
                    : 'Opens a confirmation step — nothing is posted until you confirm.'
                }
                className="px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 disabled:cursor-not-allowed min-h-[44px]"
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
              <thead className="bg-[#E6ECEA]"><tr><th className="px-3 py-2 text-left text-xs text-gray-500">Item</th><th className="px-3 py-2 text-left text-xs text-gray-500">Qty</th><th className="px-3 py-2 text-left text-xs text-gray-500">Unit cost</th><th className="px-3 py-2 text-left text-xs text-gray-500">Total</th></tr></thead>
              <tbody>
                {(grn.lines || []).map((l) => (
                  <tr key={l.id}><td className="px-3 py-2">{formatItemDisplayName(l.item)}</td><td>{l.qty}</td><td>{l.unit_cost}</td><td><span className="tabular-nums">{formatMoney(parseFloat(String(l.line_total)))}</span></td></tr>
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
        title={`Record to accounts: ${term('grn')}`}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This will increase stock in the selected store and record the receipt in your accounts for the posting date below. You can cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(postingDate), label: 'Posting date chosen' },
              { ok: Boolean(store_id), label: 'Store set on draft' },
              { ok: docDateReady, label: 'Document date set' },
              { ok: validDraftLineCount > 0, label: 'At least one line with quantity > 0' },
            ]}
            blockingHint={!canConfirmRecord ? 'Complete required fields before recording.' : undefined}
          />
          <OperatorErrorCallout error={postM.isError ? formatOperatorError(postM.error) : null} />
          <FormField label="Posting date" required>
            <input type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} className="w-full px-3 py-2 border rounded min-h-[44px]" />
          </FormField>
          <FormField label="Idempotency Key">
            <input value={idempotencyKey} readOnly className="w-full px-3 py-2 border rounded bg-gray-100 text-xs" />
          </FormField>
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
              disabled={postM.isPending || !canConfirmRecord}
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
        title={`${term('reverseAction')}: ${term('grn')}`}
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This creates offsetting stock and accounting entries as of the posting date below. Cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(reversePostingDate), label: 'Posting date chosen' },
              { ok: Boolean(reverseReason.trim()), label: 'Reason entered' },
            ]}
            blockingHint={!canConfirmGrnReverse ? 'Choose a posting date and enter a reason before reversing.' : undefined}
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
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={2}
            />
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
              disabled={!canConfirmGrnReverse || reverseM.isPending}
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
