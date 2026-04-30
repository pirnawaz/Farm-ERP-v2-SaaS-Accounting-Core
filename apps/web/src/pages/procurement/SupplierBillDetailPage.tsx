import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { supplierBillsApi, type SupplierBill } from '../../lib/api/procurement/supplierBills';
import { supplierBillMatchesApi, type SupplierBillLineMatch } from '../../lib/api/procurement/supplierBillMatches';

export default function SupplierBillDetailPage() {
  const { id } = useParams();
  const [bill, setBill] = useState<SupplierBill | null>(null);
  const [loading, setLoading] = useState(true);
  const [postingDate, setPostingDate] = useState<string>(() => new Date().toISOString().slice(0, 10));
  const [matches, setMatches] = useState<SupplierBillLineMatch[]>([]);
  const [matchesLoading, setMatchesLoading] = useState(false);
  const [matchesSaving, setMatchesSaving] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    supplierBillsApi
      .get(id!)
      .then((r) => {
        if (!active) return;
        setBill(r);
      })
      .catch((e) => toast.error(e?.message ?? 'Failed to load bill'))
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id]);

  useEffect(() => {
    if (!id) return;
    let active = true;
    setMatchesLoading(true);
    supplierBillMatchesApi
      .get(id)
      .then((r) => {
        if (!active) return;
        setMatches(r.matches ?? []);
      })
      .catch(() => {})
      .finally(() => {
        if (!active) return;
        setMatchesLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id]);

  if (loading) return <div className="p-6 text-sm text-gray-600">Loading…</div>;
  if (!bill) return <div className="p-6 text-sm text-gray-600">Not found.</div>;

  const isDraft = bill.status === 'DRAFT';
  const checklist = [
    { label: 'Supplier selected', ok: !!bill.supplier_id },
    { label: 'Posting date selected', ok: !!postingDate },
    { label: 'Lines present', ok: (bill.lines?.length ?? 0) > 0 },
    {
      label: 'All lines allocated to project + crop cycle',
      ok: (bill.lines ?? []).every((l: any) => !!(l.project_id && l.crop_cycle_id)),
    },
    { label: 'Credit premium reviewed', ok: true },
    { label: 'Totals present', ok: !!bill.grand_total },
  ];

  return (
    <div className="p-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold">Supplier bill</h2>
          <div className="text-sm text-gray-600 mt-1">
            {bill.supplier?.name ?? bill.supplier_id} • {bill.bill_date} • {bill.payment_terms} • {bill.status}
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            className={`px-3 py-2 rounded text-sm ${isDraft ? 'bg-[#1F6F5C] text-white' : 'bg-gray-200 text-gray-600 cursor-not-allowed pointer-events-none'}`}
            to={`/app/procurement/supplier-bills/${bill.id}/edit`}
          >
            Edit
          </Link>
        </div>
      </div>

      <div className="mt-4 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded p-3">
        <b>Deprecated AP path</b> — use <Link className="text-[#1F6F5C] hover:underline" to="/app/accounting/supplier-invoices">Supplier Invoices</Link>.
        This screen is kept for viewing existing supplier bills. Posting remains available only for legacy continuity.
      </div>

      {isDraft ? (
        <div className="mt-4 bg-white border rounded p-4">
          <div className="flex items-end justify-between gap-4">
            <div className="flex-1">
              <div className="text-sm font-medium">Posting checklist</div>
              <ul className="mt-2 text-sm space-y-1">
                {checklist.map((c) => (
                  <li key={c.label} className={c.ok ? 'text-green-700' : 'text-amber-700'}>
                    {c.ok ? 'OK' : 'FIX'} — {c.label}
                  </li>
                ))}
              </ul>
            </div>
            <div className="w-64">
              <label className="block text-sm text-gray-600">Posting date</label>
              <input className="mt-1 w-full border rounded px-3 py-2" type="date" value={postingDate} onChange={(e) => setPostingDate(e.target.value)} />
              <button
                className="mt-3 w-full px-3 py-2 rounded bg-gray-200 text-gray-700 text-sm cursor-not-allowed"
                onClick={() => toast.error('Posting via Supplier Bills is deprecated. Use Supplier Invoices.')}
                disabled
              >
                Post bill (disabled)
              </button>
              <div className="mt-2 text-xs text-gray-500">
                Posting creates one immutable Posting Group, allocation rows, and balanced ledger entries.
              </div>
            </div>
          </div>
        </div>
      ) : null}

      <div className="mt-4 bg-white border rounded p-4">
        <div className="grid grid-cols-3 gap-4 text-sm">
          <div>
            <div className="text-gray-500">Cash subtotal</div>
            <div className="font-medium">{bill.subtotal_cash_amount}</div>
          </div>
          <div>
            <div className="text-gray-500">Credit premium</div>
            <div className="font-medium">{bill.credit_premium_total}</div>
          </div>
          <div>
            <div className="text-gray-500">Total payable</div>
            <div className="font-semibold">{bill.grand_total}</div>
          </div>
          <div>
            <div className="text-gray-500">Paid</div>
            <div className="font-medium">{bill.paid_amount ?? '—'}</div>
          </div>
          <div>
            <div className="text-gray-500">Outstanding</div>
            <div className="font-semibold">{bill.outstanding_amount ?? '—'}</div>
          </div>
          <div>
            <div className="text-gray-500">Payment status</div>
            <div className="font-medium">{bill.payment_status ?? '—'}</div>
          </div>
        </div>
      </div>

      <div className="mt-4 bg-white border rounded overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-gray-600">
            <tr>
              <th className="text-left px-3 py-2">Line</th>
              <th className="text-left px-3 py-2">Description</th>
              <th className="text-right px-3 py-2">Qty</th>
              <th className="text-right px-3 py-2">Cash unit</th>
              <th className="text-right px-3 py-2">Credit unit</th>
              <th className="text-right px-3 py-2">Selected unit</th>
              <th className="text-right px-3 py-2">Premium</th>
              <th className="text-right px-3 py-2">Line total</th>
            </tr>
          </thead>
          <tbody>
            {(bill.lines ?? []).map((l) => (
              <tr key={l.id} className="border-t">
                <td className="px-3 py-2">{l.line_no}</td>
                <td className="px-3 py-2">{l.description ?? ''}</td>
                <td className="px-3 py-2 text-right">{String(l.qty)}</td>
                <td className="px-3 py-2 text-right">{String(l.cash_unit_price)}</td>
                <td className="px-3 py-2 text-right">{l.credit_unit_price == null ? '—' : String(l.credit_unit_price)}</td>
                <td className="px-3 py-2 text-right">{l.selected_unit_price}</td>
                <td className="px-3 py-2 text-right">{l.credit_premium_amount}</td>
                <td className="px-3 py-2 text-right font-medium">{l.line_total}</td>
              </tr>
            ))}
            {(bill.lines ?? []).length === 0 ? (
              <tr>
                <td className="px-3 py-6 text-gray-500" colSpan={8}>
                  No lines.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      <div className="mt-4 bg-white border rounded p-4">
        <div className="flex items-center justify-between">
          <div>
            <div className="text-sm font-medium">PO / GRN matches (traceability only)</div>
            <div className="text-xs text-gray-500 mt-1">Does not post accounting or change inventory history.</div>
          </div>
          <button
            className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm disabled:opacity-50"
            disabled={matchesSaving || matchesLoading || !isDraft}
            onClick={async () => {
              if (!bill) return;
              setMatchesSaving(true);
              try {
                // Default: keep existing matches; allow editing via the JSON textarea below.
                const payload = matches.map((m) => ({
                  supplier_bill_line_id: m.supplier_bill_line_id,
                  purchase_order_line_id: m.purchase_order_line_id ?? null,
                  grn_line_id: m.grn_line_id ?? null,
                  matched_qty: Number(m.matched_qty),
                  matched_amount: Number(m.matched_amount),
                }));
                await supplierBillMatchesApi.sync(bill.id, { matches: payload });
                toast.success('Matches saved');
                const refreshed = await supplierBillMatchesApi.get(bill.id);
                setMatches(refreshed.matches ?? []);
              } catch (e: any) {
                toast.error(e?.message ?? 'Save matches failed');
              } finally {
                setMatchesSaving(false);
              }
            }}
          >
            {matchesSaving ? 'Saving…' : 'Save matches'}
          </button>
        </div>

        {matchesLoading ? <div className="mt-3 text-sm text-gray-600">Loading matches…</div> : null}

        <div className="mt-3 overflow-x-auto border rounded">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-gray-600">
              <tr>
                <th className="text-left px-3 py-2">Bill line</th>
                <th className="text-left px-3 py-2">PO line</th>
                <th className="text-left px-3 py-2">GRN line</th>
                <th className="text-right px-3 py-2">Qty</th>
                <th className="text-right px-3 py-2">Amount</th>
              </tr>
            </thead>
            <tbody>
              {matches.map((m) => (
                <tr key={m.id} className="border-t">
                  <td className="px-3 py-2">{m.supplier_bill_line_id.slice(0, 8)}</td>
                  <td className="px-3 py-2">{m.purchaseOrderLine?.purchaseOrder?.po_no ?? m.purchase_order_line_id ?? '—'}</td>
                  <td className="px-3 py-2">
                    {m.grnLine?.grn?.doc_no ?? m.grn_line_id ?? '—'}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{m.matched_qty}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{m.matched_amount}</td>
                </tr>
              ))}
              {matches.length === 0 && !matchesLoading ? (
                <tr>
                  <td className="px-3 py-6 text-gray-500" colSpan={5}>
                    No matches recorded. (Optional)
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        {!isDraft ? (
          <div className="mt-3 text-xs text-gray-500">Matches are view-only for non-draft bills in this UI.</div>
        ) : null}
      </div>
    </div>
  );
}

