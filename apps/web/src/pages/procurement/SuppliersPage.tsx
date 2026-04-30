import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { suppliersApi, type Supplier } from '../../lib/api/procurement/suppliers';

export default function SuppliersPage() {
  const [items, setItems] = useState<Supplier[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    suppliersApi
      .list()
      .then((r) => {
        if (!active) return;
        setItems(r);
        setError(null);
      })
      .catch((e) => {
        if (!active) return;
        setError(e?.message ?? 'Failed to load suppliers');
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
        <h2 className="text-xl font-semibold">Suppliers</h2>
        <Link className="px-3 py-2 rounded bg-[#1F6F5C] text-white text-sm" to="/app/procurement/suppliers/new">
          New supplier
        </Link>
      </div>

      {loading ? <div className="mt-4 text-sm text-gray-600">Loading…</div> : null}
      {error ? <div className="mt-4 text-sm text-red-600">{error}</div> : null}

      <div className="mt-4 bg-white rounded border overflow-hidden">
        <table className="w-full text-sm">
          <thead className="bg-gray-50 text-gray-600">
            <tr>
              <th className="text-left px-3 py-2">Name</th>
              <th className="text-left px-3 py-2">Status</th>
              <th className="text-right px-3 py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            {items.map((s) => (
              <tr key={s.id} className="border-t">
                <td className="px-3 py-2">{s.name}</td>
                <td className="px-3 py-2">{s.status}</td>
                <td className="px-3 py-2 text-right">
                  <Link className="text-[#1F6F5C] hover:underline" to={`/app/procurement/suppliers/${s.id}`}>
                    View / Edit
                  </Link>
                </td>
              </tr>
            ))}
            {items.length === 0 && !loading ? (
              <tr>
                <td className="px-3 py-6 text-gray-500" colSpan={3}>
                  No suppliers yet.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </div>
  );
}

