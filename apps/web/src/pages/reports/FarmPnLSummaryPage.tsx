import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiClient } from '@farm-erp/shared';
import type { FarmPnLSummaryResponse } from '@farm-erp/shared';
import { PageContainer } from '../../components/PageContainer';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropCycles } from '../../hooks/useCropCycles';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

export default function FarmPnLSummaryPage() {
  const { formatMoney } = useFormatting();
  const { data: cycles = [] } = useCropCycles();
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [cropCycleId, setCropCycleId] = useState('');

  const params = useMemo(
    () => ({
      from,
      to,
      ...(cropCycleId ? { crop_cycle_id: cropCycleId } : {}),
    }),
    [from, to, cropCycleId]
  );

  const { data, isLoading, error } = useQuery<FarmPnLSummaryResponse, Error>({
    queryKey: ['reports', 'farm-pnl', params],
    queryFn: () => apiClient.getFarmPnLSummary(params),
    enabled: !!from && !!to,
  });

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title="Farm P&L"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Farm P&L' }]}
      />
      <p className="text-sm text-gray-600 -mt-2">
        Management view: <strong>field-cycle (project)</strong> revenue and costs, plus <strong>cost-center overhead</strong>,
        combined without double-counting. Figures are from posted accounts only (
        <Link to="/app/reports/project-pl" className="text-[#1F6F5C] hover:underline">
          project P&amp;L
        </Link>{' '}
        +{' '}
        <Link to="/app/reports/overheads" className="text-[#1F6F5C] hover:underline">
          overheads
        </Link>
        ).
      </p>

      <section className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-lg font-semibold text-gray-900">Filters</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
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
            <span className="text-gray-600 text-xs font-medium uppercase">Crop cycle (project side only)</span>
            <select
              className="mt-1 w-full rounded border border-gray-300 px-3 py-2"
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
            >
              <option value="">All seasons</option>
              {cycles.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
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
          <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="rounded-lg bg-white shadow p-4 ring-1 ring-[#1F6F5C]/20">
              <p className="text-xs font-medium text-gray-500 uppercase">Project revenue</p>
              <p className="text-xl font-semibold tabular-nums">{formatMoney(data.projects.totals.income)}</p>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Project costs</p>
              <p className="text-xl font-semibold tabular-nums">{formatMoney(data.projects.totals.expenses)}</p>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Project profit</p>
              <p className="text-xl font-semibold tabular-nums">{formatMoney(data.projects.totals.net_profit)}</p>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <p className="text-xs font-medium text-gray-500 uppercase">Overhead net</p>
              <p className="text-xl font-semibold tabular-nums">{formatMoney(data.overhead.grand_totals.net)}</p>
            </div>
          </section>

          <div className="rounded-lg bg-[#1F6F5C] text-white shadow p-6">
            <p className="text-sm font-medium opacity-90">Net farm operating result</p>
            <p className="text-3xl font-bold tabular-nums mt-1">{formatMoney(data.combined.net_farm_operating_result)}</p>
            <p className="text-xs opacity-80 mt-2">
              Project profit ({formatMoney(data.projects.totals.net_profit)}) + overhead P&amp;L impact (
              {formatMoney(data.overhead.grand_totals.net)})
            </p>
          </div>

          <section className="bg-white rounded-lg shadow overflow-hidden">
            <h2 className="text-lg font-semibold p-6 pb-0">Projects</h2>
            <div className="overflow-x-auto p-6 pt-4">
              {data.projects.rows.length === 0 ? (
                <p className="text-sm text-gray-500">No project P&amp;L in range{cropCycleId ? ' for this crop cycle' : ''}.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-left text-gray-500">
                      <th className="py-2 pr-4">Project</th>
                      <th className="py-2 pr-4 text-right">Revenue</th>
                      <th className="py-2 pr-4 text-right">Costs</th>
                      <th className="py-2 text-right">Profit</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.projects.rows.map((r) => (
                      <tr key={r.project_id} className="border-b border-gray-100">
                        <td className="py-2 pr-4">
                          <Link to={`/app/reports/project-profitability?project_id=${r.project_id}`} className="text-[#1F6F5C] hover:underline">
                            {r.project_name ?? r.project_id.slice(0, 8)}
                          </Link>
                        </td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.income)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.expenses)}</td>
                        <td className="py-2 text-right tabular-nums font-medium">{formatMoney(r.net_profit)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </section>

          <section className="bg-white rounded-lg shadow overflow-hidden">
            <h2 className="text-lg font-semibold p-6 pb-0">Overhead by cost center</h2>
            <div className="overflow-x-auto p-6 pt-4">
              {data.overhead.by_cost_center.length === 0 ? (
                <p className="text-sm text-gray-500">No posted overhead in this period.</p>
              ) : (
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 text-left text-gray-500">
                      <th className="py-2 pr-4">Cost center</th>
                      <th className="py-2 pr-4 text-right">Expenses</th>
                      <th className="py-2 text-right">Net</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.overhead.by_cost_center.map((r) => (
                      <tr key={r.cost_center_id} className="border-b border-gray-100">
                        <td className="py-2 pr-4">{r.cost_center_name ?? r.cost_center_id.slice(0, 8)}</td>
                        <td className="py-2 pr-4 text-right tabular-nums">{formatMoney(r.expenses)}</td>
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
