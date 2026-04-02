import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useProfitLoss } from '../../hooks/useReports';
import { term } from '../../config/terminology';
import { EMPTY_COPY } from '../../config/presentation';
import { ReportErrorState, ReportKindBadge, ReportLoadingState, ReportMetadataBlock, ReportFilterCard, ReportPage, ReportSectionCard, ReportEmptyState } from '../../components/report';
import { FilterBar, FilterCheckboxField, FilterField, FilterGrid } from '../../components/FilterBar';

const defaultFrom = () => {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  return d.toISOString().split('T')[0];
};
const defaultTo = () => new Date().toISOString().split('T')[0];

export default function ProfitLossPage() {
  const { formatMoney, formatDateRange } = useFormatting();
  const [from, setFrom] = useState(defaultFrom());
  const [to, setTo] = useState(defaultTo());
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareFrom, setCompareFrom] = useState('');
  const [compareTo, setCompareTo] = useState('');

  const params = {
    from,
    to,
    ...(compareEnabled && compareFrom && compareTo ? { compare_from: compareFrom, compare_to: compareTo } : {}),
  };
  const { data, isLoading, error } = useProfitLoss(params);

  return (
    <ReportPage>
      <PageHeader
        title={term('profitAndLoss')}
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Profit & Reports', to: '/app/reports' }, { label: term('profitAndLoss') }]}
        right={<ReportKindBadge kind="accounting" />}
      />

      <ReportMetadataBlock reportingPeriodRange={formatDateRange(from, to)} />

      <ReportFilterCard>
        <FilterBar>
          <FilterGrid className="lg:grid-cols-4 xl:grid-cols-4">
            <FilterField label="From">
              <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </FilterField>
            <FilterField label="To">
              <input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </FilterField>
            <div className="sm:pt-7">
              <FilterCheckboxField
                id="compare-period"
                label="Compare period"
                checked={compareEnabled}
                onChange={(checked) => {
                  setCompareEnabled(checked);
                  if (!checked) {
                    setCompareFrom('');
                    setCompareTo('');
                  }
                }}
              />
            </div>
            {compareEnabled && (
              <>
                <FilterField label="Compare from">
                  <input type="date" value={compareFrom} onChange={(e) => setCompareFrom(e.target.value)} />
                </FilterField>
                <FilterField label="Compare to">
                  <input type="date" value={compareTo} onChange={(e) => setCompareTo(e.target.value)} />
                </FilterField>
              </>
            )}
          </FilterGrid>
        </FilterBar>
      </ReportFilterCard>

      {isLoading && <ReportLoadingState label="Loading profit & loss..." />}
      {error && <ReportErrorState error={error} />}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">Period</div>
              <div className="font-medium">{data.from} to {data.to}</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">Net profit</div>
              <div className={`text-xl font-semibold tabular-nums ${data.net_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                {formatMoney(data.net_profit)}
              </div>
            </div>
          </div>

          {data.sections.map((section) => (
            <ReportSectionCard key={section.key}>
              <div className="px-4 py-3 bg-[#E6ECEA] font-medium text-gray-800">{section.label}</div>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                      <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                      <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                      {data.compare && (
                        <>
                          <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Compare</th>
                          <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Delta</th>
                        </>
                      )}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {section.lines.length === 0 ? (
                      <ReportEmptyState colSpan={data.compare ? 5 : 3} message={EMPTY_COPY.noRecords} />
                    ) : (
                      section.lines.map((line, idx) => (
                        <tr key={line.account_id ?? idx}>
                          <td className="px-4 py-2 text-sm text-gray-900">{line.code ?? '—'}</td>
                          <td className="px-4 py-2 text-sm text-gray-700">{line.name}</td>
                          <td className="px-4 py-2 text-sm text-right tabular-nums">{formatMoney(line.amount)}</td>
                          {data.compare && (
                            <>
                              <td className="px-4 py-2 text-sm text-right tabular-nums text-gray-600">
                                {line.compare_amount != null ? formatMoney(line.compare_amount) : '—'}
                              </td>
                              <td className="px-4 py-2 text-sm text-right tabular-nums">
                                {line.delta != null ? formatMoney(line.delta) : '—'}
                              </td>
                            </>
                          )}
                        </tr>
                      ))
                    )}
                  </tbody>
                  <tfoot className="bg-gray-50 font-medium">
                    <tr>
                      <td colSpan={2} className="px-4 py-2 text-sm text-gray-700">Total {section.label}</td>
                      <td className="px-4 py-2 text-sm text-right tabular-nums">{formatMoney(section.total)}</td>
                      {data.compare && <td colSpan={2} />}
                    </tr>
                  </tfoot>
                </table>
              </div>
            </ReportSectionCard>
          ))}

          {data.compare && (
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm font-medium text-gray-700 mb-2">Compare period: {data.compare.from} to {data.compare.to}</div>
              <div className="flex flex-col sm:flex-row gap-2 sm:gap-4">
                <span className="text-sm text-gray-600">Compare net profit: {formatMoney(data.compare.net_profit)}</span>
                <span className="text-sm text-gray-600">Delta: {formatMoney(data.compare.delta)}</span>
              </div>
            </div>
          )}
        </>
      )}
    </ReportPage>
  );
}
