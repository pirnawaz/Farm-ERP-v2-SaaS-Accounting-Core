import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { supplierBillsApi, type SupplierBill } from '../../lib/api/procurement/supplierBills';

export default function SupplierBillsPage() {
  const [items, setItems] = useState<SupplierBill[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    supplierBillsApi
      .list()
      .then((r) => {
        if (!active) return;
        setItems(r);
        setError(null);
      })
      .catch((e) => {
        if (!active) return;
        setError(e?.message ?? 'Failed to load bills');
      })
      .finally(() => {
        if (!active) return;
        setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  return (
    <div className="p-6">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">Supplier Bills (Deprecated)</h2>
        <span className="px-3 py-2 rounded bg-gray-200 text-gray-700 text-sm cursor-not-allowed">
          New bill disabled
        </span>
      </div>

      <div className="mt-4 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded p-3">
        <b>Deprecated AP path</b> — use <Link className="text-[#1F6F5C] hover:underline" to="/app/accounting/supplier-invoices">Supplier Invoices</Link>.
        Existing supplier bills remain viewable for reference.
      </div>

      {loading ? <div className="mt-4 text-sm text-gray-600">Loading…</div> : null}
      {error ? <div className="mt-4 text-sm text-red-600">{error}</div> : null}

      <div className="mt-4 bg-white rounded border overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-gray-600">
            <tr>
              <th className="text-left px-3 py-2">Date</th>
              <th className="text-left px-3 py-2">Supplier</th>
              <th className="text-left px-3 py-2">Terms</th>
              <th className="text-right px-3 py-2">Cash subtotal</th>
              <th className="text-right px-3 py-2">Credit premium</th>
              <th className="text-right px-3 py-2">Total payable</th>
              <th className="text-right px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {items.map((b) => (
              <tr key={b.id} className="border-t">
                <td className="px-3 py-2">{b.bill_date}</td>
                <td className="px-3 py-2">{b.supplier?.name ?? b.supplier_id}</td>
                <td className="px-3 py-2">{b.payment_terms}</td>
                <td className="px-3 py-2 text-right">{b.subtotal_cash_amount}</td>
                <td className="px-3 py-2 text-right">{b.credit_premium_total}</td>
                <td className="px-3 py-2 text-right font-medium">{b.grand_total}</td>
                <td className="px-3 py-2 text-right">
                  <Link className="text-[#1F6F5C] hover:underline" to={`/app/procurement/supplier-bills/${b.id}`}>
                    View / Edit
                  </Link>
                </td>
              </tr>
            ))}
            {items.length === 0 && !loading ? (
              <tr>
                <td className="px-3 py-6 text-gray-500" colSpan={7}>
                  No bills yet.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </div>
  );
}

