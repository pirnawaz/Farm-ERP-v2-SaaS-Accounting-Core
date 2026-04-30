import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { suppliersApi, type Supplier } from '../../lib/api/procurement/suppliers';
import { supplierBillsApi, type SupplierBillPaymentTerms, type SupplierBill, type UpsertSupplierBillLinePayload } from '../../lib/api/procurement/supplierBills';

function toNum(x: any): number {
  const n = typeof x === 'number' ? x : parseFloat(String(x ?? '0'));
  return Number.isFinite(n) ? n : 0;
}

export default function SupplierBillFormPage() {
  const { id } = useParams();
  const isNew = useMemo(() => !id || id === 'new', [id]);
  const navigate = useNavigate();

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [bill, setBill] = useState<SupplierBill | null>(null);

  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [supplierId, setSupplierId] = useState('');
  const [billDate, setBillDate] = useState('');
  const [currencyCode, setCurrencyCode] = useState('GBP');
  const [paymentTerms, setPaymentTerms] = useState<SupplierBillPaymentTerms>('CASH');
  const [referenceNo, setReferenceNo] = useState('');
  const [notes, setNotes] = useState('');

  const [lines, setLines] = useState<UpsertSupplierBillLinePayload[]>([
    { description: '', qty: 1, cash_unit_price: 0, credit_unit_price: null },
  ]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    Promise.all([
      suppliersApi.list(),
      isNew ? Promise.resolve(null as SupplierBill | null) : supplierBillsApi.get(id!),
    ])
      .then(([sRes, bRes]) => {
        if (!active) return;
        setSuppliers(sRes);
        if (!isNew && bRes) {
          setBill(bRes);
          setSupplierId(bRes.supplier_id);
          setBillDate(bRes.bill_date);
          setCurrencyCode(bRes.currency_code);
          setPaymentTerms(bRes.payment_terms);
          setReferenceNo(bRes.reference_no ?? '');
          setNotes(bRes.notes ?? '');
          setLines(
            (bRes.lines ?? []).map((l) => ({
              line_no: l.line_no,
              description: l.description ?? '',
              qty: toNum(l.qty),
              cash_unit_price: toNum(l.cash_unit_price),
              credit_unit_price: l.credit_unit_price == null ? null : toNum(l.credit_unit_price),
            })),
          );
        } else if (isNew) {
          // Default bill date today for convenience
          const today = new Date().toISOString().slice(0, 10);
          setBillDate(today);
        }
      })
      .catch((e) => {
        toast.error(e?.message ?? 'Failed to load');
      })
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id, isNew]);

  const addLine = () => setLines((prev) => [...prev, { description: '', qty: 1, cash_unit_price: 0, credit_unit_price: null }]);
  const removeLine = (idx: number) => setLines((prev) => prev.filter((_, i) => i !== idx));

  const updateLine = (idx: number, next: Partial<UpsertSupplierBillLinePayload>) =>
    setLines((prev) => prev.map((l, i) => (i === idx ? { ...l, ...next } : l)));

  const onSave = async () => {
    if (isNew) {
      toast.error('Deprecated AP path — create supplier invoices instead.');
      return;
    }
    if (!supplierId) {
      toast.error('Select a supplier');
      return;
    }
    if (!billDate) {
      toast.error('Select a bill date');
      return;
    }

    setSaving(true);
    try {
      if (isNew) {
        const r = await supplierBillsApi.create({
          supplier_id: supplierId,
          reference_no: referenceNo || null,
          bill_date: billDate,
          currency_code: currencyCode,
          payment_terms: paymentTerms,
          notes: notes || null,
          lines,
        });
        toast.success('Bill created');
        navigate(`/app/procurement/supplier-bills/${r.id}`);
      } else {
        const r = await supplierBillsApi.update(id!, {
          supplier_id: supplierId,
          reference_no: referenceNo || null,
          bill_date: billDate,
          currency_code: currencyCode,
          payment_terms: paymentTerms,
          notes: notes || null,
          lines,
        });
        setBill(r);
        toast.success('Bill updated');
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  const locked = Boolean(!isNew && bill && bill.status !== 'DRAFT');
  const deprecatedNewDisabled = isNew;

  return (
    <div className="p-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">{isNew ? 'New supplier bill' : 'Supplier bill'}</h2>
        <div className="flex items-center gap-2">
          {!isNew ? (
            <Link className="px-3 py-2 rounded border text-sm" to={`/app/procurement/supplier-bills/${id}`}>
              Refresh
            </Link>
          ) : null}
          <button
            className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm disabled:opacity-50"
            onClick={onSave}
            disabled={saving || loading || locked || deprecatedNewDisabled}
          >
            {saving ? 'Saving…' : locked ? 'Locked' : 'Save'}
          </button>
        </div>
      </div>

      <div className="mt-4 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded p-3">
        <b>Deprecated AP path</b> — use{' '}
        <Link className="text-[#1F6F5C] hover:underline" to="/app/accounting/supplier-invoices">
          Supplier Invoices
        </Link>
        . Existing supplier bills remain viewable.
      </div>

      {loading ? <div className="mt-4 text-sm text-gray-600">Loading…</div> : null}
      {bill && bill.status !== 'DRAFT' ? (
        <div className="mt-4 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">
          This bill is <b>{bill.status}</b> and cannot be edited.
        </div>
      ) : null}

      <div className="mt-4 grid grid-cols-3 gap-4">
        <div
          className={`bg-white border rounded p-4 space-y-3 col-span-2 ${deprecatedNewDisabled ? 'opacity-60 pointer-events-none select-none' : ''}`}
        >
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm text-gray-600">Supplier</label>
              <select className="mt-1 w-full border rounded px-3 py-2" value={supplierId} onChange={(e) => setSupplierId(e.target.value)} disabled={locked}>
                <option value="">Select…</option>
                {suppliers.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm text-gray-600">Bill date</label>
              <input className="mt-1 w-full border rounded px-3 py-2" type="date" value={billDate} onChange={(e) => setBillDate(e.target.value)} disabled={locked} />
            </div>
          </div>

          <div className="grid grid-cols-3 gap-4">
            <div>
              <label className="block text-sm text-gray-600">Terms</label>
              <select className="mt-1 w-full border rounded px-3 py-2" value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value as SupplierBillPaymentTerms)} disabled={locked}>
                <option value="CASH">CASH</option>
                <option value="CREDIT">CREDIT</option>
              </select>
            </div>
            <div>
              <label className="block text-sm text-gray-600">Currency</label>
              <input className="mt-1 w-full border rounded px-3 py-2" value={currencyCode} onChange={(e) => setCurrencyCode(e.target.value.toUpperCase())} disabled={locked} />
            </div>
            <div>
              <label className="block text-sm text-gray-600">Reference</label>
              <input className="mt-1 w-full border rounded px-3 py-2" value={referenceNo} onChange={(e) => setReferenceNo(e.target.value)} disabled={locked} />
            </div>
          </div>

          <div>
            <label className="block text-sm text-gray-600">Lines</label>
            <div className="mt-2 space-y-2">
              {lines.map((l, idx) => (
                <div key={idx} className="border rounded p-3">
                  <div className="grid grid-cols-6 gap-2 items-end">
                    <div className="col-span-2">
                      <label className="block text-xs text-gray-500">Description</label>
                      <input className="mt-1 w-full border rounded px-2 py-1" value={l.description ?? ''} onChange={(e) => updateLine(idx, { description: e.target.value })} disabled={locked} />
                    </div>
                    <div>
                      <label className="block text-xs text-gray-500">Qty</label>
                      <input className="mt-1 w-full border rounded px-2 py-1" type="number" step="0.000001" value={l.qty} onChange={(e) => updateLine(idx, { qty: toNum(e.target.value) })} disabled={locked} />
                    </div>
                    <div>
                      <label className="block text-xs text-gray-500">Cash unit</label>
                      <input className="mt-1 w-full border rounded px-2 py-1" type="number" step="0.000001" value={l.cash_unit_price} onChange={(e) => updateLine(idx, { cash_unit_price: toNum(e.target.value) })} disabled={locked} />
                    </div>
                    <div>
                      <label className="block text-xs text-gray-500">Credit unit</label>
                      <input className="mt-1 w-full border rounded px-2 py-1" type="number" step="0.000001" value={l.credit_unit_price ?? ''} onChange={(e) => updateLine(idx, { credit_unit_price: e.target.value === '' ? null : toNum(e.target.value) })} disabled={locked} />
                    </div>
                    <div className="text-right">
                      <button className="text-sm text-red-600 hover:underline disabled:opacity-50" onClick={() => removeLine(idx)} disabled={locked || lines.length <= 1}>
                        Remove
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
            <button className="mt-3 px-3 py-2 rounded border text-sm disabled:opacity-50" onClick={addLine} disabled={locked}>
              Add line
            </button>
          </div>

          <div>
            <label className="block text-sm text-gray-600">Notes</label>
            <textarea className="mt-1 w-full border rounded px-3 py-2" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} disabled={locked} />
          </div>
        </div>

        <div className="bg-white border rounded p-4 space-y-3">
          <div className="text-sm text-gray-600">Server-calculated totals</div>
          <div className="grid grid-cols-2 text-sm">
            <div className="text-gray-500">Cash subtotal</div>
            <div className="text-right font-medium">{bill?.subtotal_cash_amount ?? '—'}</div>
          </div>
          <div className="grid grid-cols-2 text-sm">
            <div className="text-gray-500">Credit premium</div>
            <div className="text-right font-medium">{bill?.credit_premium_total ?? '—'}</div>
          </div>
          <div className="grid grid-cols-2 text-sm border-t pt-3">
            <div className="text-gray-700">Total payable</div>
            <div className="text-right font-semibold">{bill?.grand_total ?? '—'}</div>
          </div>

          {bill?.lines?.length ? (
            <div className="mt-2 text-xs text-gray-500">
              Tip: switch CASH/CREDIT to see the premium change (credit premium = credit total − cash total).
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}

