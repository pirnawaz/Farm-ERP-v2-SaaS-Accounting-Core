import { LoadingSpinner } from '../LoadingSpinner';
import { useProjectForecast, useProjectProjectedProfit } from '../../hooks/useReports';
import { varianceClass } from './planningVarianceStyles';

export interface PlanningInsightPanelsProps {
  projectId: string;
  from?: string;
  to?: string;
  cropCycleId?: string;
  /** Format currency for display (numbers from forecast APIs). */
  formatMoney: (value: number) => string;
}

/**
 * Forecast (planned vs actual + gap) and pre-harvest projected profit — shared by planning and report routes.
 */
export function PlanningInsightPanels(props: PlanningInsightPanelsProps) {
  const { projectId, from, to, cropCycleId, formatMoney } = props;
  const params = { project_id: projectId, from, to, crop_cycle_id: cropCycleId };
  const forecast = useProjectForecast(params, { enabled: !!projectId });
  const projected = useProjectProjectedProfit(params, { enabled: !!projectId });

  const fc = forecast.data;
  const ph = projected.data;

  return (
    <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <section className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-gray-900 mb-1">Forecast</h2>
        <p className="text-sm text-gray-600 mb-4">
          Compare the numbers you planned to what the books show for the dates you picked.
        </p>
        {!projectId && <p className="text-sm text-gray-500">Choose a field to see this.</p>}
        {projectId && forecast.isLoading && (
          <div className="flex justify-center py-10">
            <LoadingSpinner />
          </div>
        )}
        {projectId && forecast.error && (
          <p className="text-sm text-rose-700">{forecast.error.message}</p>
        )}
        {projectId && fc && (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-left text-gray-600">
                  <th className="py-2 pr-4 font-medium"> </th>
                  <th className="py-2 pr-4 font-medium tabular-nums">Planned</th>
                  <th className="py-2 pr-4 font-medium tabular-nums">Actual</th>
                  <th className="py-2 font-medium tabular-nums">Gap</th>
                </tr>
              </thead>
              <tbody className="text-gray-900">
                <tr className="border-b border-gray-100">
                  <td className="py-2 pr-4">Income</td>
                  <td className="py-2 pr-4 tabular-nums">{formatMoney(fc.planned.revenue)}</td>
                  <td className="py-2 pr-4 tabular-nums">{formatMoney(fc.actual.revenue)}</td>
                  <td className={`py-2 tabular-nums ${varianceClass(fc.variance.revenue, 'revenue')}`}>
                    {formatMoney(fc.variance.revenue)}
                  </td>
                </tr>
                <tr className="border-b border-gray-100">
                  <td className="py-2 pr-4">Costs</td>
                  <td className="py-2 pr-4 tabular-nums">{formatMoney(fc.planned.cost)}</td>
                  <td className="py-2 pr-4 tabular-nums">{formatMoney(fc.actual.cost)}</td>
                  <td className={`py-2 tabular-nums ${varianceClass(fc.variance.cost, 'cost')}`}>
                    {formatMoney(fc.variance.cost)}
                  </td>
                </tr>
                <tr>
                  <td className="py-2 pr-4 font-medium">Profit</td>
                  <td className="py-2 pr-4 tabular-nums font-medium">{formatMoney(fc.planned.profit)}</td>
                  <td className="py-2 pr-4 tabular-nums font-medium">{formatMoney(fc.actual.profit)}</td>
                  <td className={`py-2 tabular-nums font-medium ${varianceClass(fc.variance.profit, 'profit')}`}>
                    {formatMoney(fc.variance.profit)}
                  </td>
                </tr>
              </tbody>
            </table>
            <p className="text-xs text-gray-500 mt-3">
              Green on income and profit means ahead of plan. For costs, green means you spent less than planned.
            </p>
          </div>
        )}
      </section>

      <section className="rounded-xl border border-amber-100 bg-amber-50/60 p-6 shadow-sm">
        <h2 className="text-lg font-semibold text-amber-950 mb-1">Before harvest</h2>
        <p className="text-sm text-amber-900/80 mb-4">
          Uses your planned crop value versus money already spent (up to the &quot;to&quot; date, or today if you leave it blank).
        </p>
        {projectId && projected.isLoading && (
          <div className="flex justify-center py-10">
            <LoadingSpinner />
          </div>
        )}
        {projectId && projected.error && (
          <p className="text-sm text-rose-700">{projected.error.message}</p>
        )}
        {projectId && ph && (
          <div className="space-y-4">
            <div className="rounded-lg bg-white/90 border border-amber-100/80 p-4">
              <p className="text-xs font-medium text-amber-900/70 uppercase tracking-wide">Planned crop value</p>
              <p className="text-2xl font-semibold tabular-nums text-amber-950">{formatMoney(ph.projected_revenue)}</p>
            </div>
            <div className="rounded-lg bg-white/90 border border-amber-100/80 p-4">
              <p className="text-xs font-medium text-amber-900/70 uppercase tracking-wide">Spent so far</p>
              <p className="text-2xl font-semibold tabular-nums text-amber-950">{formatMoney(ph.projected_cost)}</p>
            </div>
            <div
              className={`rounded-lg p-4 border ${
                ph.projected_profit >= 0
                  ? 'bg-emerald-50 border-emerald-200'
                  : 'bg-rose-50 border-rose-200'
              }`}
            >
              <p className="text-xs font-medium text-gray-700 uppercase tracking-wide">Projected profit</p>
              <p
                className={`text-2xl font-bold tabular-nums ${
                  ph.projected_profit >= 0 ? 'text-emerald-800' : 'text-rose-700'
                }`}
              >
                {formatMoney(ph.projected_profit)}
              </p>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}
