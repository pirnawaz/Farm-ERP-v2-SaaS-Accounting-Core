import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { useProductionUnitsProfitability } from '../../hooks/useReports';
import { useFormatting } from '../../hooks/useFormatting';
import type { ProductionUnitCategoryFilter, ProductionUnitProfitabilityRow } from '../../types';
import { ReportLoadingState, ReportEmptyStateCard, ReportFilterCard, ReportKindBadge, ReportMetadataBlock, ReportPage } from '../../components/report';
import { EMPTY_COPY } from '../../config/presentation';
import { FilterBar, FilterField, FilterGrid } from '../../components/FilterBar';
import { KpiCard } from '../../components/KpiCard';

function startOfCurrentMonth(): string {
  const d = new Date();
  d.setDate(1);
  return d.toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

const CATEGORY_OPTIONS: Array<{ value: '' | ProductionUnitCategoryFilter; label: string }> = [
  { value: '', label: 'All units' },
  { value: 'ORCHARD', label: 'Orchards' },
  { value: 'LIVESTOCK', label: 'Livestock' },
  { value: 'OTHER', label: 'Other long-lived units (advanced)' },
];

export default function ProductionUnitsProfitabilityReportPage() {
  const { formatMoney, formatDateRange } = useFormatting();
  const [from, setFrom] = useState(startOfCurrentMonth);
  const [to, setTo] = useState(today);
  const [category, setCategory] = useState<'' | ProductionUnitCategoryFilter>('ORCHARD');

  const toBeforeFrom = to < from;
  const params = useMemo(
    () => ({
      from,
      to,
      category: category || undefined,
    }),
    [from, to, category]
  );

  const { data, isLoading, isFetching } = useProductionUnitsProfitability(params, {
    enabled: !toBeforeFrom && !!from && !!to,
  });

  const rows = (data?.rows ?? []) as ProductionUnitProfitabilityRow[];

  return (
    <ReportPage>
      <PageHeader
        title="Orchard & Livestock performance"
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Profit & Reports', to: '/app/reports' }, { label: 'Orchard & Livestock performance' }]}
        right={<ReportKindBadge kind="analytics" />}
      />

      <ReportMetadataBlock reportingPeriodRange={formatDateRange(from, to)} />

      <ReportFilterCard className="space-y-3">
        <p className="text-sm text-gray-600">
          Operational analytics across time. This report summarizes posted activity tagged to an orchard/livestock unit (not an accounting boundary).
        </p>
        <FilterBar>
          <FilterGrid className="lg:grid-cols-4">
            <FilterField label="From">
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </FilterField>
            <FilterField label="To">
              <input
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className={toBeforeFrom ? 'border-red-500 bg-red-50' : undefined}
              />
              {toBeforeFrom && <p className="text-sm text-red-600 mt-1">To date must be on or after from date.</p>}
            </FilterField>
            <FilterField label="Units">
              <select value={category} onChange={(e) => setCategory(e.target.value as '' | ProductionUnitCategoryFilter)}>
                {CATEGORY_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </FilterField>
            <KpiCard
              label="Total margin"
              value={formatMoney(data?.totals?.margin ?? '0')}
              padding="compact"
              className="rounded-lg border border-gray-200 bg-gray-50"
            />
          </FilterGrid>
        </FilterBar>
      </ReportFilterCard>

      {isLoading || isFetching ? (
        <ReportLoadingState />
      ) : data ? (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          {rows.length === 0 ? (
            <div className="p-8 text-center text-gray-500">
              <div className="max-w-md mx-auto">
                <ReportEmptyStateCard message={EMPTY_COPY.noDataForPeriod} className="shadow-none p-0 bg-transparent" />
                <div className="mt-3 text-sm text-gray-500">
                  Tip: tag work logs, issues, harvests and sales to an orchard/livestock unit to see continuity reporting.
                </div>
              </div>
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-[#E6ECEA]">
                    <tr>
                      <th scope="col" className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-left">
                        Unit
                      </th>
                      <th scope="col" className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">
                        Revenue
                      </th>
                      <th scope="col" className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">
                        Cost
                      </th>
                      <th scope="col" className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-right">
                        Margin
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {rows.map((r) => (
                      <tr key={r.production_unit_id} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm text-gray-900">
                          <span className="font-medium">{r.production_unit_name}</span>
                          {r.production_unit_category && (
                            <span className="ml-2 text-xs text-gray-500">({r.production_unit_category})</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-900 text-right tabular-nums">{formatMoney(r.revenue)}</td>
                        <td className="px-4 py-3 text-sm text-gray-900 text-right tabular-nums">{formatMoney(r.cost)}</td>
                        <td className="px-4 py-3 text-sm text-gray-900 text-right tabular-nums">{formatMoney(r.margin)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="p-4 bg-gray-50 border-t">
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 text-sm font-medium">
                  <div>
                    Total revenue: <span className="tabular-nums">{formatMoney(data.totals.revenue)}</span>
                  </div>
                  <div>
                    Total cost: <span className="tabular-nums">{formatMoney(data.totals.cost)}</span>
                  </div>
                  <div>
                    Total margin: <span className="tabular-nums">{formatMoney(data.totals.margin)}</span>
                  </div>
                </div>
              </div>
            </>
          )}
        </div>
      ) : (
        <ReportEmptyStateCard message={EMPTY_COPY.generic} />
      )}

      <div className="text-sm text-gray-600">
        For accounting views, use{' '}
        <Link className="text-[#1F6F5C] hover:underline font-medium" to="/app/reports/crop-profitability">
          Crop Profitability
        </Link>{' '}
        (by crop/cycle over a reporting period).
      </div>
    </ReportPage>
  );
}

