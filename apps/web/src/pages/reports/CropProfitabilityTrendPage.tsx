import { useState, useMemo } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropProfitabilityTrend } from '../../hooks/useReports';
import type {
  CropProfitabilityTrendGroupBy,
  CropProfitabilityTrendPoint,
} from '../../types';

const n = (v?: string | null) => (v ? Number(v) : 0);
const fmt = (v?: string | null) => v ?? '—';

/** First day of the month, 11 months ago (so last 12 months with today) */
function defaultFrom(): string {
  const d = new Date();
  d.setMonth(d.getMonth() - 11);
  d.setDate(1);
  return d.toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

const TOP_SERIES = 5;

export default function CropProfitabilityTrendPage() {
  const { formatMoney } = useFormatting();
  const [from, setFrom] = useState(defaultFrom);
  const [to, setTo] = useState(today);
  const [groupBy, setGroupBy] = useState<CropProfitabilityTrendGroupBy>('category');
  const [includeUnassigned, setIncludeUnassigned] = useState(false);

  const toBeforeFrom = to < from;
  const params = useMemo(
    () => ({
      from,
      to,
      group_by: groupBy,
      include_unassigned: includeUnassigned,
    }),
    [from, to, groupBy, includeUnassigned]
  );
  const { data, isLoading, isFetching } = useCropProfitabilityTrend(params, {
    enabled: !toBeforeFrom && !!from && !!to,
  });

  const { topSeries, months } = useMemo(() => {
    if (!data?.series?.length || !data?.months?.length) {
      return { topSeries: [], months: data?.months ?? [] };
    }
    const sorted = [...data.series].sort(
      (a, b) => n(b.totals.margin) - n(a.totals.margin)
    );
    const top = sorted.slice(0, TOP_SERIES);
    return {
      topSeries: top,
      months: data.months,
    };
  }, [data?.series, data?.months]);

  const unassignedSeries = data?.series?.find((s) => s.key === 'unassigned');
  const hasUnassignedAmounts =
    !!unassignedSeries &&
    (n(unassignedSeries.totals.revenue) > 0 || n(unassignedSeries.totals.cost) > 0);

  const tableRows = useMemo(() => {
    if (!data?.series) return [];
    const rows: { month: string; label: string; point: CropProfitabilityTrendPoint }[] = [];
    for (const s of data.series) {
      for (const p of s.points) {
        rows.push({ month: p.month, label: s.label, point: p });
      }
    }
    rows.sort((a, b) => {
      const m = a.month.localeCompare(b.month);
      return m !== 0 ? m : a.label.localeCompare(b.label);
    });
    return rows;
  }, [data?.series]);

  const maxMarginPerAcre = useMemo(() => {
    let max = 0;
    for (const s of topSeries) {
      for (const p of s.points) {
        const v = n(p.margin_per_acre);
        if (v > max) max = v;
      }
    }
    return max;
  }, [topSeries]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Crop Profitability Trend"
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'Crop Profitability Trend' },
        ]}
      />

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className={`w-full border rounded px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] ${
                toBeforeFrom ? 'border-red-500 bg-red-50' : 'border-gray-300'
              }`}
            />
            {toBeforeFrom && (
              <p className="text-sm text-red-600 mt-1">To date must be on or after from date.</p>
            )}
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Group by</label>
            <select
              value={groupBy}
              onChange={(e) => setGroupBy(e.target.value as CropProfitabilityTrendGroupBy)}
              className="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="category">Category</option>
              <option value="crop">Crop</option>
              <option value="all">All</option>
            </select>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="trend-include-unassigned"
              checked={includeUnassigned}
              onChange={(e) => setIncludeUnassigned(e.target.checked)}
              className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
            />
            <label htmlFor="trend-include-unassigned" className="text-sm font-medium text-gray-700">
              Include unassigned
            </label>
          </div>
        </div>
      </div>

      {!includeUnassigned && (
        <div className="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-sm">
          Unassigned postings are currently excluded. Enable &quot;Include unassigned&quot; to see
          missing crop tagging.
        </div>
      )}

      {includeUnassigned && hasUnassignedAmounts && unassignedSeries && (
        <div className="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-lg text-sm">
          <strong>Missing crop tagging detected:</strong>{' '}
          {formatMoney(unassignedSeries.totals.revenue)} revenue and{' '}
          {formatMoney(unassignedSeries.totals.cost)} cost are not assigned to a crop cycle. Tag
          revenue and cost to crop cycles for accurate trends.
        </div>
      )}

      {isLoading || isFetching ? (
        <div className="bg-white rounded-lg shadow p-8 text-center text-gray-500">
          Loading…
        </div>
      ) : data ? (
        <>
          {data.series.length === 0 ? (
            <div className="bg-white rounded-lg shadow p-8 text-center text-gray-500">
              No data for selected period.
            </div>
          ) : (
            <>
              {/* Trend chart: margin per acre by month for top series */}
              <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-200">
                  <h2 className="text-lg font-semibold text-gray-900">
                    Margin per acre by month
                    {data.series.length > TOP_SERIES && (
                      <span className="ml-2 text-sm font-normal text-gray-500">
                        (top {TOP_SERIES} by total margin)
                      </span>
                    )}
                  </h2>
                </div>
                <div className="overflow-x-auto p-4">
                  {topSeries.length > 0 && months.length > 0 ? (
                    <table className="min-w-full text-sm">
                      <thead>
                        <tr className="border-b border-gray-200">
                          <th className="text-left py-2 pr-4 font-medium text-gray-700">Series</th>
                          {months.map((m) => (
                            <th
                              key={m}
                              className="text-right py-2 px-1 font-medium text-gray-500 tabular-nums"
                            >
                              {m}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {topSeries.map((s) => (
                          <tr key={s.key} className="border-b border-gray-100">
                            <td className="py-2 pr-4 font-medium text-gray-900">{s.label}</td>
                            {months.map((month) => {
                              const point = s.points.find((p) => p.month === month);
                              const val = point ? n(point.margin_per_acre) : null;
                              const pct =
                                maxMarginPerAcre > 0 && val != null && val >= 0
                                  ? Math.min(100, (val / maxMarginPerAcre) * 100)
                                  : 0;
                              return (
                                <td key={month} className="py-2 px-1 text-right">
                                  {point ? (
                                    <div className="flex items-center justify-end gap-2">
                                      <div
                                        className="h-5 bg-[#1F6F5C]/20 rounded min-w-[2rem]"
                                        style={{
                                          width: `${pct}%`,
                                          maxWidth: '80px',
                                        }}
                                        title={fmt(point.margin_per_acre)}
                                      />
                                      <span
                                        className={
                                          val != null && val >= 0
                                            ? 'text-green-600 tabular-nums'
                                            : 'text-red-600 tabular-nums'
                                        }
                                      >
                                        {point.margin_per_acre != null
                                          ? formatMoney(point.margin_per_acre)
                                          : '—'}
                                      </span>
                                    </div>
                                  ) : (
                                    <span className="text-gray-400">—</span>
                                  )}
                                </td>
                              );
                            })}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  ) : (
                    <p className="text-gray-500 text-sm">No series to display.</p>
                  )}
                </div>
              </div>

              {/* Table: Month, Series, Acres, Revenue, Cost, Margin, Margin/Acre */}
              <div className="bg-white rounded-lg shadow overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-200">
                  <h2 className="text-lg font-semibold text-gray-900">Trend data</h2>
                </div>
                <div className="overflow-x-auto">
                  {tableRows.length > 0 ? (
                    <table className="min-w-full divide-y divide-gray-200">
                      <thead className="bg-[#E6ECEA]">
                        <tr>
                          <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Month
                          </th>
                          <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Series
                          </th>
                          <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acres
                          </th>
                          <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Revenue
                          </th>
                          <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Cost
                          </th>
                          <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Margin
                          </th>
                          <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Margin / Acre
                          </th>
                        </tr>
                      </thead>
                      <tbody className="bg-white divide-y divide-gray-200">
                        {tableRows.map((r, i) => (
                          <tr key={`${r.month}-${r.label}-${i}`} className="hover:bg-gray-50">
                            <td className="px-4 py-2 text-sm text-gray-900">{r.month}</td>
                            <td className="px-4 py-2 text-sm font-medium text-gray-900">
                              {r.label}
                            </td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums text-gray-700">
                              {fmt(r.point.acres)}
                            </td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums text-gray-700">
                              {formatMoney(r.point.revenue)}
                            </td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums text-gray-700">
                              {formatMoney(r.point.cost)}
                            </td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums font-medium">
                              <span
                                className={
                                  n(r.point.margin) >= 0 ? 'text-green-600' : 'text-red-600'
                                }
                              >
                                {formatMoney(r.point.margin)}
                              </span>
                            </td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums font-medium">
                              <span
                                className={
                                  n(r.point.margin_per_acre) >= 0
                                    ? 'text-green-600'
                                    : 'text-red-600'
                                }
                              >
                                {r.point.margin_per_acre != null
                                  ? formatMoney(r.point.margin_per_acre)
                                  : '—'}
                              </span>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  ) : (
                    <div className="p-8 text-center text-gray-500">No trend data.</div>
                  )}
                </div>
              </div>
            </>
          )}
        </>
      ) : null}
    </div>
  );
}
