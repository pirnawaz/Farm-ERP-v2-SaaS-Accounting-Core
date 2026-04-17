import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import type { OverheadsReportResponse } from '@farm-erp/shared';
import { PageContainer } from '../../components/PageContainer';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useCostCenters } from '../../hooks/useCostCenters';
import { useParties } from '../../hooks/useParties';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

export default function OverheadsReportPage() {
  const { formatMoney } = useFormatting();
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [costCenterId, setCostCenterId] = useState('');
  const [partyId, setPartyId] = useState('');

  const { data: costCenters = [] } = useCostCenters();
  const { data: parties = [] } = useParties();

  const params = useMemo(
    () => ({
      from,
      to,
      ...(costCenterId ? { cost_center_id: costCenterId } : {}),
      ...(partyId ? { party_id: partyId } : {}),
    }),
    [from, to, costCenterId, partyId]
  );

  const { data, isLoading, error } = useQuery<OverheadsReportResponse, Error>({
    queryKey: ['reports', 'overheads', params],
    queryFn: () => apiClient.getOverheadsReport(params),
    enabled: !!from && !!to,
  });

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Overheads"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Overheads' }]}
      />
      <p className="text-sm text-gray-600 -mt-2">
        Posted farm overhead from <strong>cost centers</strong> (utilities, admin, insurance, and other bills). Uses
        posting dates and the same P&amp;L account rules as field-cycle reports — drafts are excluded.
      </p>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">Filters</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
          <label className="block">
            <span className="text-gray-600 text-xs font-medium uppercase">From</span>
            <input
              type="date"
              className="mt-1 w-full rounded border border-gray-300 px-3 py-2"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
            />
          </label>
          <label className="block">
            <span className="text-gray-600 text-xs font-medium uppercase">To</span>
            <input
              type="date"
              className="mt-1 w-full rounded border border-gray-300 px-3 py-2"
              value={to}
              onChange={(e) => setTo(e.target.value)}
            />
          </label>
          <label className="block">
            <span className="text-gray-600 text-xs font-medium uppercase">Cost center</span>
            <select
              className="mt-1 w-full rounded border border-gray-300 px-3 py-2"
              value={costCenterId}
              onChange={(e) => setCostCenterId(e.target.value)}
            >
              <option value="">All</option>
              {costCenters.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                  {c.code ? ` (${c.code})` : ''}
                </option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="text-gray-600 text-xs font-medium uppercase">Supplier (optional)</span>
            <select
              className="mt-1 w-full rounded border border-gray-300 px-3 py-2"
              value={partyId}
              onChange={(e) => setPartyId(e.target.value)}
            >
              <option value="">All</option>
              {parties.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </label>
        </div>
      </section>

      {isLoading && <div className="text-gray-600">Loading…</div>}
      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">{error.message}</div>
      )}

      {data && !isLoading && (
        <>
          <section className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Overhead expenses</p>
              <p className="text-2xl font-semibold tabular-nums text-gray-900">{formatMoney(data.grand_totals.expenses)}</p>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Overhead income (if any)</p>
              <p className="text-2xl font-semibold tabular-nums text-gray-900">{formatMoney(data.grand_totals.income)}</p>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Net (P&amp;L impact)</p>
              <p className="text-2xl font-semibold tabular-nums text-gray-900">{formatMoney(data.grand_totals.net)}</p>
            </div>
          </section>

          <section className="bg-white rounded-lg shadow overflow-hidden">
            <h2 className="text-lg font-semibold p-6 pb-0">By cost center</h2>
            <div className="overflow-x-auto p-6 pt-4">
              {data.by_cost_center.length === 0 ? (
                <p className="text-sm text-gray-500">No posted overhead in this period.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-left text-gray-500">
                      <th className="py-2 pr-4">Cost center</th>
                      <th className="py-2 pr-4 text-right">Expenses</th>
                      <th className="py-2 pr-4 text-right">Income</th>
                      <th className="py-2 text-right">Net</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.by_cost_center.map((r) => (
                      <tr key={`${r.cost_center_id}-${r.currency_code}`} className="border-b border-gray-100">
                        <td className="py-2 pr-4">{r.cost_center_name ?? r.cost_center_id.slice(0, 8)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.expenses)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.income)}</td>
                        <td className="py-2 text-right tabular-nums font-medium">{formatMoney(r.net)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </section>

          <section className="bg-white rounded-lg shadow overflow-hidden">
            <h2 className="text-lg font-semibold p-6 pb-0">By account</h2>
            <div className="overflow-x-auto p-6 pt-4 max-h-[28rem]">
              {data.by_account.length === 0 ? (
                <p className="text-sm text-gray-500">No account lines.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead className="sticky top-0 bg-white">
                    <tr className="border-b border-gray-200 text-left text-gray-500">
                      <th className="py-2 pr-4">Cost center</th>
                      <th className="py-2 pr-4">Account</th>
                      <th className="py-2 pr-4 text-right">Expenses</th>
                      <th className="py-2 pr-4 text-right">Income</th>
                      <th className="py-2 text-right">Net</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.by_account.map((r) => (
                      <tr key={`${r.cost_center_id}-${r.account_id}`} className="border-b border-gray-100">
                        <td className="py-2 pr-4 whitespace-nowrap">{r.cost_center_name ?? '—'}</td>
                        <td className="py-2 pr-4">
                          {r.account_code ?? '—'} — {r.account_name ?? '—'}
                        </td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.expenses)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.income)}</td>
                        <td className="py-2 text-right tabular-nums">{formatMoney(r.net)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </section>
        </>
      )}
    </PageContainer>
  );
}
