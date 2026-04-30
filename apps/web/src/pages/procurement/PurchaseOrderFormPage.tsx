import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { suppliersApi, type Supplier } from '../../lib/api/procurement/suppliers';
import { purchaseOrdersApi, type PurchaseOrder } from '../../lib/api/procurement/purchaseOrders';

type LineDraft = {
  line_no: number;
  description: string;
  item_id?: string | null;
  qty_ordered: number;
  qty_overbill_tolerance: number;
  expected_unit_cost?: number | null;
};

export default function PurchaseOrderFormPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const editing = !!id;

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [po, setPo] = useState<PurchaseOrder | null>(null);
  const [supplierId, setSupplierId] = useState('');
  const [poNo, setPoNo] = useState('');
  const [poDate, setPoDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<LineDraft[]>([{ line_no: 1, description: '', qty_ordered: 1, qty_overbill_tolerance: 0 }]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    suppliersApi.list().then(setSuppliers).catch(() => {});
  }, []);

  useEffect(() => {
    if (!editing) return;
    purchaseOrdersApi
      .get(id!)
      .then((r) => {
        setPo(r);
        setSupplierId(r.supplier_id);
        setPoNo(r.po_no);
        setPoDate(r.po_date);
        setNotes(r.notes ?? '');
        setLines(
          (r.lines ?? []).map((l) => ({
            line_no: l.line_no,
            description: l.description ?? '',
            item_id: l.item_id ?? null,
            qty_ordered: Number(l.qty_ordered),
            qty_overbill_tolerance: Number(l.qty_overbill_tolerance),
            expected_unit_cost: l.expected_unit_cost != null ? Number(l.expected_unit_cost) : null,
          })),
        );
      })
      .catch((e) => toast.error(e?.message ?? 'Failed to load PO'));
  }, [editing, id]);

  const canEdit = !po || po.status === 'DRAFT';

  const addLine = () =>
    setLines((prev) => [...prev, { line_no: prev.length + 1, description: '', qty_ordered: 1, qty_overbill_tolerance: 0 }]);

  const onSave = async () => {
    if (!supplierId || !poNo || !poDate) {
      toast.error('Supplier, PO # and date are required');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        supplier_id: supplierId,
        po_no: poNo,
        po_date: poDate,
        notes: notes || null,
        lines: lines.map((l) => ({
          line_no: l.line_no,
          description: l.description || null,
          item_id: l.item_id || null,
          qty_ordered: l.qty_ordered,
          qty_overbill_tolerance: l.qty_overbill_tolerance,
          expected_unit_cost: l.expected_unit_cost ?? null,
        })),
      };
      const res = editing ? await purchaseOrdersApi.update(id!, payload) : await purchaseOrdersApi.create(payload);
      toast.success('Saved');
      navigate(`/app/procurement/purchase-orders/${res.id}`);
    } catch (e: any) {
      toast.error(e?.message ?? 'Save failed');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold">{editing ? `Purchase Order · ${poNo || id}` : 'New purchase order'}</h2>
          {po?.status && <div className="text-sm text-gray-600 mt-1">Status: {po.status}</div>}
        </div>
        {editing ? (
          <Link className="text-sm text-[#1F6F5C] hover:underline" to={`/app/procurement/purchase-orders/${id}`}>
            Back to detail
          </Link>
        ) : (
          <Link className="text-sm text-[#1F6F5C] hover:underline" to="/app/procurement/purchase-orders">
            Back to list
          </Link>
        )}
      </div>

      {!canEdit ? (
        <div className="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-900">
          This PO is <strong>{po?.status}</strong> and is read-only.
        </div>
      ) : null}

      <div className="bg-white border rounded p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <label className="text-sm">
          Supplier
          <select className="mt-1 w-full border rounded px-3 py-2" value={supplierId} onChange={(e) => setSupplierId(e.target.value)} disabled={!canEdit}>
            <option value="">Select…</option>
            {suppliers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </label>
        <label className="text-sm">
          PO #
          <input className="mt-1 w-full border rounded px-3 py-2" value={poNo} onChange={(e) => setPoNo(e.target.value)} disabled={!canEdit} />
        </label>
        <label className="text-sm">
          PO date
          <input className="mt-1 w-full border rounded px-3 py-2" type="date" value={poDate} onChange={(e) => setPoDate(e.target.value)} disabled={!canEdit} />
        </label>
        <label className="text-sm md:col-span-2">
          Notes
          <textarea className="mt-1 w-full border rounded px-3 py-2" value={notes} onChange={(e) => setNotes(e.target.value)} disabled={!canEdit} />
        </label>
      </div>

      <div className="bg-white border rounded overflow-hidden">
        <div className="px-4 py-3 flex items-center justify-between">
          <div className="font-medium">Lines</div>
          <button className="text-sm text-[#1F6F5C] hover:underline" type="button" onClick={addLine} disabled={!canEdit}>
            Add line
          </button>
        </div>
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-gray-600">
            <tr>
              <th className="text-left px-3 py-2">#</th>
              <th className="text-left px-3 py-2">Description</th>
              <th className="text-right px-3 py-2">Qty ordered</th>
              <th className="text-right px-3 py-2">Overbill tolerance</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l, idx) => (
              <tr key={idx} className="border-t">
                <td className="px-3 py-2">{l.line_no}</td>
                <td className="px-3 py-2">
                  <input
                    className="w-full border rounded px-2 py-1"
                    value={l.description}
                    onChange={(e) => setLines((prev) => prev.map((x, i) => (i === idx ? { ...x, description: e.target.value } : x)))}
                    disabled={!canEdit}
                  />
                </td>
                <td className="px-3 py-2 text-right">
                  <input
                    className="w-28 border rounded px-2 py-1 text-right"
                    type="number"
                    step="0.000001"
                    value={l.qty_ordered}
                    onChange={(e) => setLines((prev) => prev.map((x, i) => (i === idx ? { ...x, qty_ordered: Number(e.target.value) } : x)))}
                    disabled={!canEdit}
                  />
                </td>
                <td className="px-3 py-2 text-right">
                  <input
                    className="w-28 border rounded px-2 py-1 text-right"
                    type="number"
                    step="0.000001"
                    value={l.qty_overbill_tolerance}
                    onChange={(e) =>
                      setLines((prev) => prev.map((x, i) => (i === idx ? { ...x, qty_overbill_tolerance: Number(e.target.value) } : x)))
                    }
                    disabled={!canEdit}
                  />
                </td>
              </tr>
            ))}
            {lines.length === 0 ? (
              <tr>
                <td className="px-3 py-6 text-gray-500" colSpan={4}>
                  No lines.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      <button className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm disabled:opacity-50" onClick={onSave} disabled={!canEdit || saving}>
        {saving ? 'Saving…' : 'Save'}
      </button>
    </div>
  );
}

