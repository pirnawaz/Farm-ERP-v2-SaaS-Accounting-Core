import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@farm-erp/shared';
import { PageHeader } from '../../components/PageHeader';
import { ReportPage, ReportSectionCard } from '../../components/report';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropCycles } from '../../hooks/useCropCycles';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

type MoneyStr = string;

type Planned = {
  planned_input_cost: MoneyStr;
  planned_labour_cost: MoneyStr;
  planned_machinery_cost: MoneyStr;
  planned_total_cost: MoneyStr;
  planned_yield_qty: string | null;
  planned_yield_value: MoneyStr | null;
};
type Actual = {
  actual_input_cost: MoneyStr;
  actual_labour_cost: MoneyStr;
  actual_machinery_cost: MoneyStr;
  actual_credit_premium_cost: MoneyStr;
  actual_total_cost: MoneyStr;
  actual_yield_qty: string | null;
  actual_yield_value: MoneyStr | null;
};
type Variance = {
  variance_input_cost: MoneyStr;
  variance_labour_cost: MoneyStr;
  variance_machinery_cost: MoneyStr;
  variance_credit_premium_cost: MoneyStr;
  variance_total_cost: MoneyStr;
  variance_yield_qty: string | null;
  variance_yield_value: MoneyStr | null;
};

type SeriesRow = { month: string; planned: Planned; actual: Actual; variance: Variance };
type ProjectTotalsRow = { project_id: string; project_name: string; planned: Planned; actual: Actual; variance: Variance };

type CropCycleBudgetVsActualResponse = {
  scope: { tenant_id: string; crop_cycle_id: string };
  currency_code: string;
  period: { from: string; to: string; bucket: 'month' };
  series: SeriesRow[];
  totals: { planned: Planned; actual: Actual; variance: Variance };
  projects: ProjectTotalsRow[];
  _meta?: Record<string, unknown>;
};

function pct(variance: number, planned: number): string {
  if (!Number.isFinite(variance) || !Number.isFinite(planned) || Math.abs(planned) < 0.000001) return '—';
  return `${((variance / planned) * 100).toFixed(1)}%`;
}

export default function CropCycleBudgetVsActualReportPage() {
  const { formatMoney } = useFormatting();
  const { data: cropCycles = [] } = useCropCycles();
  const [searchParams] = useSearchParams();

  const [cropCycleId, setCropCycleId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);

  useEffect(() => {
    const cc = searchParams.get('crop_cycle_id');
    const f = searchParams.get('from');
    const t = searchParams.get('to');
    if (cc) setCropCycleId(cc);
    if (f) setFrom(f);
    if (t) setTo(t);
  }, [searchParams]);

  const params = useMemo(
    () => ({
      crop_cycle_id: cropCycleId,
      ...(from ? { from } : {}),
      ...(to ? { to } : {}),
      bucket: 'month' as const,
    }),
    [cropCycleId, from, to]
  );

  const { data, isLoading, error } = useQuery<CropCycleBudgetVsActualResponse, Error>({
    queryKey: ['reports', 'budget-vs-actual', 'crop-cycle', params],
    queryFn: async () => {
      const qs = new URLSearchParams(params as any);
      const res = await apiClient.get<CropCycleBudgetVsActualResponse>(`/api/reports/budget-vs-actual/crop-cycle?${qs.toString()}`);
      return (res as unknown as { data?: CropCycleBudgetVsActualResponse }).data ?? res;
    },
    enabled: Boolean(cropCycleId),
  });

  const totals = data?.totals;
  const plannedTotal = totals ? Number(totals.planned.planned_total_cost) : 0;
  const actualTotal = totals ? Number(totals.actual.actual_total_cost) : 0;
  const varianceTotal = totals ? Number(totals.variance.variance_total_cost) : 0;
  const premiumTotal = totals ? Number(totals.actual.actual_credit_premium_cost) : 0;
  const yieldComingSoon = totals?.actual.actual_yield_qty == null && totals?.actual.actual_yield_value == null;

  return (
    <ReportPage>
      <PageHeader
        title="Budget vs Actual (crop cycle)"
        description="Rolled up across all projects in the crop cycle. Credit premium is shown separately."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Budget vs Actual (crop cycle)' },
        ]}
      />

      <p className="text-sm text-gray-600">
        Planned monthly values are evenly distributed until time-phased budgets are added.
      </p>

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm text-gray-700 mb-1">Crop cycle</label>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select…</option>
              {cropCycles.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
        </div>
      </div>

      {!cropCycleId ? <div className="text-center text-sm text-gray-500 py-8">Select a crop cycle to load the report.</div> : null}
      {isLoading ? <div className="text-gray-600">Loading…</div> : null}
      {error ? <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800">{error.message}</div> : null}

      {data && !isLoading ? (
        <>
          <section className="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Planned total cost</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(plannedTotal)}</div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Actual total cost</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(actualTotal)}</div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Variance (actual − planned)</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">
                {formatMoney(varianceTotal)} <span className="text-sm text-gray-500">({pct(varianceTotal, plannedTotal)})</span>
              </div>
            </div>
            <div className="rounded-lg bg-white shadow p-4">
              <div className="text-xs text-gray-500 uppercase">Credit premium</div>
              <div className="text-xl font-semibold mt-1 tabular-nums">{formatMoney(premiumTotal)}</div>
            </div>
          </section>

          <ReportSectionCard>
            <div className="p-4 border-b">
              <h2 className="text-sm font-semibold text-gray-900">Monthly breakdown</h2>
            </div>
            <div className="overflow-x-auto border rounded">
              <table className="min-w-[1100px] w-full text-sm">
                <thead className="bg-gray-50 text-gray-600">
                  <tr>
                    <th className="text-left px-3 py-2">Month</th>
                    <th className="text-right px-3 py-2">Planned total</th>
                    <th className="text-right px-3 py-2">Actual total</th>
                    <th className="text-right px-3 py-2">Credit premium</th>
                    <th className="text-right px-3 py-2">Variance</th>
                    <th className="text-right px-3 py-2">Var %</th>
                  </tr>
                </thead>
                <tbody>
                  {data.series.map((r) => {
                    const pTot = Number(r.planned.planned_total_cost);
                    const vTot = Number(r.variance.variance_total_cost);
                    return (
                      <tr key={r.month} className="border-t">
                        <td className="px-3 py-2">{r.month}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{r.planned.planned_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums font-medium">{r.actual.actual_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-amber-900 font-medium">{r.actual.actual_credit_premium_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{r.variance.variance_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{pct(vTot, pTot)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </ReportSectionCard>

          <ReportSectionCard>
            <div className="p-4 border-b">
              <h2 className="text-sm font-semibold text-gray-900">Per-project variance</h2>
            </div>
            <div className="overflow-x-auto border rounded">
              <table className="min-w-[900px] w-full text-sm">
                <thead className="bg-gray-50 text-gray-600">
                  <tr>
                    <th className="text-left px-3 py-2">Project</th>
                    <th className="text-right px-3 py-2">Planned total</th>
                    <th className="text-right px-3 py-2">Actual total</th>
                    <th className="text-right px-3 py-2">Premium</th>
                    <th className="text-right px-3 py-2">Variance</th>
                    <th className="text-right px-3 py-2">Var %</th>
                  </tr>
                </thead>
                <tbody>
                  {data.projects.map((p) => {
                    const pTot = Number(p.planned.planned_total_cost);
                    const vTot = Number(p.variance.variance_total_cost);
                    return (
                      <tr key={p.project_id} className="border-t">
                        <td className="px-3 py-2">{p.project_name}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{p.planned.planned_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums font-medium">{p.actual.actual_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-amber-900 font-medium">{p.actual.actual_credit_premium_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{p.variance.variance_total_cost}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{pct(vTot, pTot)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </ReportSectionCard>

          <ReportSectionCard>
            <div className="p-4 border-b">
              <h2 className="text-sm font-semibold text-gray-900">Yield</h2>
            </div>
            {yieldComingSoon ? (
              <div className="p-4 text-sm text-gray-600">Coming soon.</div>
            ) : (
              <div className="p-4">
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                  <div className="rounded border bg-white p-3">
                    <div className="text-xs text-gray-500 uppercase">Planned yield</div>
                    <div className="mt-1 tabular-nums">
                      {totals?.planned.planned_yield_qty ?? '—'} qty
                      {totals?.planned.planned_yield_value ? (
                        <span className="text-gray-500"> · {formatMoney(Number(totals.planned.planned_yield_value))}</span>
                      ) : null}
                    </div>
                  </div>
                  <div className="rounded border bg-white p-3">
                    <div className="text-xs text-gray-500 uppercase">Actual yield</div>
                    <div className="mt-1 tabular-nums">
                      {totals?.actual.actual_yield_qty ?? '—'} qty
                      {totals?.actual.actual_yield_value ? (
                        <span className="text-gray-500"> · {formatMoney(Number(totals.actual.actual_yield_value))}</span>
                      ) : null}
                    </div>
                  </div>
                  <div className="rounded border bg-white p-3">
                    <div className="text-xs text-gray-500 uppercase">Variance (actual − planned)</div>
                    <div className="mt-1 tabular-nums">
                      {totals?.variance.variance_yield_qty ?? '—'} qty
                      {totals?.variance.variance_yield_value ? (
                        <span className="text-gray-500"> · {formatMoney(Number(totals.variance.variance_yield_value))}</span>
                      ) : null}
                    </div>
                  </div>
                </div>
              </div>
            )}
          </ReportSectionCard>
        </>
      ) : null}
    </ReportPage>
  );
}

