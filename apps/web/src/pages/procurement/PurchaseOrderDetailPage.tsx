import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { purchaseOrdersApi, type PurchaseOrder, type PurchaseOrderMatchingResponse } from '../../lib/api/procurement/purchaseOrders';

export default function PurchaseOrderDetailPage() {
  const { id } = useParams();
  const [po, setPo] = useState<PurchaseOrder | null>(null);
  const [matching, setMatching] = useState<PurchaseOrderMatchingResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [approving, setApproving] = useState(false);

  useEffect(() => {
    let active = true;
    setLoading(true);
    Promise.all([purchaseOrdersApi.get(id!), purchaseOrdersApi.matching(id!)])
      .then(([poRes, matchRes]) => {
        if (!active) return;
        setPo(poRes);
        setMatching(matchRes);
      })
      .catch((e) => toast.error(e?.message ?? 'Failed to load PO'))
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [id]);

  if (!id) return null;
  if (loading) return <div className="p-6 text-sm text-gray-600">Loading…</div>;
  if (!po) return <div className="p-6 text-sm text-gray-600">Not found.</div>;

  const canApprove = po.status === 'DRAFT' && !approving;
  const canCreateInvoice = po.status !== 'DRAFT';

  const onApprove = async () => {
    if (!canApprove) return;
    setApproving(true);
    try {
      const res = await purchaseOrdersApi.approve(po.id);
      setPo(res);
      toast.success('PO approved');
    } catch (e: any) {
      toast.error(e?.message ?? 'Approve failed');
    } finally {
      setApproving(false);
    }
  };

  return (
    <div className="p-6 space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-xl font-semibold">Purchase Order · {po.po_no}</h2>
          <div className="text-sm text-gray-600 mt-1">
            {po.supplier?.name ?? po.supplier_id} • {po.po_date} • {po.status}
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Link
            className={`px-3 py-2 rounded text-sm ${
              canCreateInvoice ? 'bg-white border border-gray-300 text-gray-800 hover:bg-gray-50' : 'bg-gray-200 text-gray-600 cursor-not-allowed pointer-events-none'
            }`}
            to={`/app/accounting/supplier-invoices/new?po_id=${po.id}`}
          >
            Create Invoice
          </Link>
          <Link
            className={`px-3 py-2 rounded text-sm ${po.status === 'DRAFT' ? 'bg-[#1F6F5C] text-white' : 'bg-gray-200 text-gray-600 cursor-not-allowed pointer-events-none'}`}
            to={`/app/procurement/purchase-orders/${po.id}/edit`}
          >
            Edit
          </Link>
          <button className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm disabled:opacity-50" onClick={onApprove} disabled={!canApprove}>
            {approving ? 'Approving…' : 'Approve'}
          </button>
        </div>
      </div>

      <div className="bg-white border rounded p-4 text-sm">
        <div className="font-medium mb-2">Matching (ordered vs received vs billed)</div>
        <div className="text-gray-600 mb-3">
          Read-only summary. PO/GRN matching and supplier invoice posting are separate events; nothing posts automatically.
        </div>
        <div className="overflow-x-auto border rounded">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 text-gray-600">
              <tr>
                <th className="text-left px-3 py-2">Line</th>
                <th className="text-left px-3 py-2">Description</th>
                <th className="text-right px-3 py-2">Ordered</th>
                <th className="text-right px-3 py-2">Received</th>
                <th className="text-right px-3 py-2">Invoiced</th>
                <th className="text-right px-3 py-2">Remaining to invoice</th>
              </tr>
            </thead>
            <tbody>
              {(matching?.lines ?? []).map((l) => (
                <tr key={l.purchase_order_line_id} className="border-t">
                  <td className="px-3 py-2">{l.line_no}</td>
                  <td className="px-3 py-2">{l.description ?? l.item_name ?? ''}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{l.qty_ordered}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{l.qty_received}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{l.qty_billed}</td>
                  <td className="px-3 py-2 text-right tabular-nums font-medium">{l.qty_remaining_to_bill}</td>
                </tr>
              ))}
              {(matching?.lines ?? []).length === 0 ? (
                <tr>
                  <td className="px-3 py-6 text-gray-500" colSpan={6}>
                    No lines.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

