import { useState, useMemo, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useCropProfitability } from '../../hooks/useReports';
import type { CropProfitabilityRow, CropProfitabilityGroupBy } from '../../types';

const n = (v?: string | null) => (v ? Number(v) : 0);
const fmt = (v?: string | null) => v ?? '—';

function startOfCurrentMonth(): string {
  const d = new Date();
  d.setDate(1);
  return d.toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

function titleCase(s: string | null | undefined): string {
  if (!s) return fmt(s);
  return s
    .split(/\s+/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}

type SortKey = 'margin_per_acre' | 'margin' | 'revenue' | 'cost' | 'acres' | 'group';

function sortValue(row: CropProfitabilityRow, key: SortKey): number | string {
  switch (key) {
    case 'margin_per_acre':
      return row.margin_per_acre != null ? n(row.margin_per_acre) : -Infinity;
    case 'margin':
      return n(row.margin);
    case 'revenue':
      return n(row.revenue);
    case 'cost':
      return n(row.cost);
    case 'acres':
      return n(row.acres);
    case 'group':
      return fmt(row.crop_display_name ?? row.category ?? row.crop_cycle_name ?? row.key);
    default:
      return 0;
  }
}

function stableSort<T>(arr: T[], compare: (a: T, b: T) => number): T[] {
  return arr
    .map((item, i) => ({ item, i }))
    .sort((a, b) => {
      const c = compare(a.item, b.item);
      return c !== 0 ? c : a.i - b.i;
    })
    .map(({ item }) => item);
}

export default function CropProfitabilityReportPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const [from, setFrom] = useState(startOfCurrentMonth);
  const [to, setTo] = useState(today);
  const [groupBy, setGroupBy] = useState<CropProfitabilityGroupBy>('crop');

  useEffect(() => {
    const fromParam = searchParams.get('from');
    const toParam = searchParams.get('to');
    if (fromParam) setFrom(fromParam);
    if (toParam) setTo(toParam);
  }, [searchParams]);
  const [includeUnassigned, setIncludeUnassigned] = useState(false);
  const [sortKey, setSortKey] = useState<SortKey>('margin_per_acre');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

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
  const { data, isLoading, isFetching } = useCropProfitability(params, {
    enabled: !toBeforeFrom && !!from && !!to,
  });

  const rows = useMemo(() => {
    if (!data?.rows?.length) return [];
    const compare = (a: CropProfitabilityRow, b: CropProfitabilityRow) => {
      const va = sortValue(a, sortKey);
      const vb = sortValue(b, sortKey);
      const numA = typeof va === 'number' ? va : 0;
      const numB = typeof vb === 'number' ? vb : 0;
      if (typeof va === 'number' && typeof vb === 'number') {
        return sortDir === 'asc' ? numA - numB : numB - numA;
      }
      const sa = String(va);
      const sb = String(vb);
      const cmp = sa.localeCompare(sb);
      return sortDir === 'asc' ? cmp : -cmp;
    };
    return stableSort([...data.rows], compare);
  }, [data?.rows, sortKey, sortDir]);

  const unassignedRow = data?.rows?.find(
    (r) => r.crop_display_name === 'Unassigned' || r.key === 'unassigned'
  );
  const hasUnassignedAmounts =
    !!unassignedRow && (n(unassignedRow.revenue) > 0 || n(unassignedRow.cost) > 0);

  const handleSort = (key: SortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    else {
      setSortKey(key);
      setSortDir(key === 'margin_per_acre' || key === 'margin' || key === 'revenue' ? 'desc' : 'asc');
    }
  };

  const Th = ({
    label,
    sortKey: key,
    className = '',
  }: {
    label: string;
    sortKey: SortKey;
    className?: string;
  }) => (
    <th
      scope="col"
      className={`px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-left ${className}`}
    >
      <button
        type="button"
        onClick={() => handleSort(key)}
        className="flex items-center gap-1 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] rounded"
      >
        {label}
        {sortKey === key && (
          <span className="text-gray-400" aria-hidden>
            {sortDir === 'asc' ? '↑' : '↓'}
          </span>
        )}
      </button>
    </th>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title="Crop Profitability"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Profit & Reports', to: '/app/reports' }, { label: 'Crop Profitability' }]}
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
              onChange={(e) => setGroupBy(e.target.value as CropProfitabilityGroupBy)}
              className="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            >
              <option value="crop">Crop</option>
              <option value="category">Category</option>
              <option value="cycle">Cycle</option>
            </select>
          </div>
          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="include-unassigned"
              checked={includeUnassigned}
              onChange={(e) => setIncludeUnassigned(e.target.checked)}
              className="rounded border-gray-300 text-[#1F6F5C] focus:ring-[#1F6F5C]"
            />
            <label htmlFor="include-unassigned" className="text-sm font-medium text-gray-700">
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

      {includeUnassigned && hasUnassignedAmounts && unassignedRow && (
        <div className="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-lg text-sm">
          <strong>Missing crop tagging detected:</strong>{' '}
          {formatMoney(unassignedRow.revenue)} revenue and {formatMoney(unassignedRow.cost)} cost
          are not assigned to a crop cycle. Tag revenue and cost to crop cycles for accurate
          profitability.
        </div>
      )}

      {isLoading || isFetching ? (
        <div className="bg-white rounded-lg shadow p-8 text-center text-gray-500">
          Loading…
        </div>
      ) : data ? (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          {rows.length === 0 ? (
            <div className="p-8 text-center text-gray-500">No data for selected period.</div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-[#E6ECEA]">
                    <tr>
                      <Th label="Group" sortKey="group" />
                      <Th label="Acres" sortKey="acres" className="text-right" />
                      <Th label="Revenue" sortKey="revenue" className="text-right" />
                      <Th label="Cost" sortKey="cost" className="text-right" />
                      <Th label="Margin" sortKey="margin" className="text-right" />
                      <Th label="Margin / Acre" sortKey="margin_per_acre" className="text-right" />
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {rows.map((row, i) => (
                      <tr key={row.key + String(i)} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm text-gray-900">
                          {groupBy === 'crop' && (
                            <>
                              <span className="font-medium">{fmt(row.crop_display_name)}</span>
                              {row.catalog_code && (
                                <span className="block text-xs text-gray-500">{row.catalog_code}</span>
                              )}
                            </>
                          )}
                          {groupBy === 'category' && (
                            <span className="font-medium">{titleCase(row.category)}</span>
                          )}
                          {groupBy === 'cycle' && (
                            <>
                              <span className="font-medium">{fmt(row.crop_cycle_name)}</span>
                              {row.crop_display_name && (
                                <span className="block text-xs text-gray-500">
                                  {row.crop_display_name}
                                </span>
                              )}
                            </>
                          )}
                        </td>
                        <td className="px-4 py-3 text-sm text-right tabular-nums text-gray-700">
                          {fmt(row.acres)}
                        </td>
                        <td className="px-4 py-3 text-sm text-right tabular-nums text-gray-700">
                          {formatMoney(row.revenue)}
                        </td>
                        <td className="px-4 py-3 text-sm text-right tabular-nums text-gray-700">
                          {formatMoney(row.cost)}
                        </td>
                        <td className="px-4 py-3 text-sm text-right tabular-nums font-medium">
                          <span
                            className={
                              n(row.margin) >= 0 ? 'text-green-600' : 'text-red-600'
                            }
                          >
                            {formatMoney(row.margin)}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-sm text-right tabular-nums font-medium">
                          <span
                            className={
                              n(row.margin_per_acre) >= 0 ? 'text-green-600' : 'text-red-600'
                            }
                          >
                            {row.margin_per_acre != null
                              ? formatMoney(row.margin_per_acre)
                              : '—'}
                          </span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-gray-50 border-t-2 border-gray-200">
                    <tr>
                      <td className="px-4 py-3 text-sm font-semibold text-gray-900">Total</td>
                      <td className="px-4 py-3 text-sm text-right tabular-nums font-semibold text-gray-900">
                        {fmt(data.totals?.acres)}
                      </td>
                      <td className="px-4 py-3 text-sm text-right tabular-nums font-semibold text-gray-900">
                        {data.totals ? formatMoney(data.totals.revenue) : '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-right tabular-nums font-semibold text-gray-900">
                        {data.totals ? formatMoney(data.totals.cost) : '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-right tabular-nums font-semibold">
                        <span
                          className={
                            data.totals && n(data.totals.margin) >= 0
                              ? 'text-green-600'
                              : 'text-red-600'
                          }
                        >
                          {data.totals ? formatMoney(data.totals.margin) : '—'}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-sm text-right tabular-nums font-semibold">
                        <span
                          className={
                            data.totals && n(data.totals.margin_per_acre) >= 0
                              ? 'text-green-600'
                              : 'text-red-600'
                          }
                        >
                          {data.totals?.margin_per_acre != null
                            ? formatMoney(data.totals.margin_per_acre)
                            : '—'}
                        </span>
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </>
          )}
        </div>
      ) : null}
    </div>
  );
}
