import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { usePayablesOutstanding } from '../../hooks/useLabour';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { PayablesOutstandingRow } from '../../types';

export default function PayablesOutstandingPage() {
  const { data: rows, isLoading } = usePayablesOutstanding();
  const navigate = useNavigate();
  const { formatMoney } = useFormatting();
  const [search, setSearch] = useState('');

  const filteredRows = useMemo(() => {
    const list = rows ?? [];
    const q = search.trim().toLowerCase();
    if (!q) return list;
    return list.filter((r) => (r.worker_name || '').toLowerCase().includes(q));
  }, [rows, search]);

  const totalOutstanding = useMemo(
    () => filteredRows.reduce((s, r) => s + parseFloat(r.payable_balance || '0'), 0),
    [filteredRows]
  );

  const hasFilters = !!search.trim();

  const clearFilters = () => setSearch('');

  const handlePay = (r: PayablesOutstandingRow) => {
    if (!r.party_id) return;
    const params = new URLSearchParams();
    params.set('party_id', r.party_id);
    params.set('purpose', 'WAGES');
    params.set('direction', 'OUT');
    if (parseFloat(r.payable_balance) > 0) params.set('amount', r.payable_balance);
    navigate(`/app/payments/new?${params.toString()}`);
  };

  const summaryLine = useMemo(() => {
    const n = filteredRows.length;
    const label = n === 1 ? 'worker with payables' : 'workers with payables';
    const base = hasFilters ? `${n} ${label} (filtered)` : `${n} ${label}`;
    if (n === 0) return base;
    return `${base} · Total outstanding ${formatMoney(totalOutstanding)}`;
  }, [filteredRows.length, hasFilters, totalOutstanding, formatMoney]);

  return (
    <div className="space-y-6 max-w-7xl">
      <PageHeader
        title="Payables"
        tooltip="Review amounts owed to workers based on recorded labour activity."
        description="Review amounts owed to workers based on recorded labour activity."
        helper="Use this page to see outstanding labour payables."
        backTo="/app/labour"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Labour Overview', to: '/app/labour' },
          { label: 'Payables' },
        ]}
      />

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="max-w-md">
          <label htmlFor="pay-search" className="block text-xs font-medium text-gray-600 mb-1">
            Search
          </label>
          <input
            id="pay-search"
            type="search"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Worker name"
            className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
          />
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <LoadingSpinner size="lg" />
          </div>
        ) : !rows || rows.length === 0 ? (
          <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
            <h3 className="text-base font-semibold text-gray-900">No payables yet.</h3>
            <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
              Amounts owed to workers will appear here after labour activity is recorded.
            </p>
          </div>
        ) : filteredRows.length === 0 ? (
          <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
            <h3 className="text-base font-semibold text-gray-900">No workers match your filters.</h3>
            <p className="mt-2 text-sm text-gray-600">Try another search or clear filters.</p>
            <button
              type="button"
              onClick={clearFilters}
              className="mt-6 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
            >
              Clear filters
            </button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Worker</th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide">Outstanding</th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {filteredRows.map((r) => (
                  <tr key={r.worker_id} className="hover:bg-gray-50/80">
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{r.worker_name}</td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                      <span className="tabular-nums font-medium">{formatMoney(r.payable_balance)}</span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                      {r.party_id ? (
                        <button
                          type="button"
                          onClick={() => handlePay(r)}
                          className="text-[#1F6F5C] hover:text-[#1a5a4a] font-medium"
                        >
                          Pay
                        </button>
                      ) : (
                        <span className="text-gray-400 text-xs">Link worker to a party in Workers to enable wage payments.</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
