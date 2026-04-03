import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useBalanceSheet } from '../../hooks/useReports';
import { term } from '../../config/terminology';
import { EMPTY_COPY } from '../../config/presentation';
import { ReportErrorState, ReportKindBadge, ReportLoadingState, ReportMetadataBlock, ReportFilterCard, ReportPage, ReportSectionCard, ReportEmptyState } from '../../components/report';
import { FilterBar, FilterField, FilterGrid, FilterCheckboxField } from '../../components/FilterBar';

type BalanceSheetLineRow = {
  account_id: string | null;
  code: string | null;
  name: string;
  amount: number;
};

const defaultAsOf = () => new Date().toISOString().split('T')[0];

export default function BalanceSheetPage() {
  const { formatMoney, formatDate } = useFormatting();
  const [asOf, setAsOf] = useState(defaultAsOf());
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareAsOf, setCompareAsOf] = useState('');

  const params = {
    as_of: asOf,
    ...(compareEnabled && compareAsOf ? { compare_as_of: compareAsOf } : {}),
  };
  const { data, isLoading, error } = useBalanceSheet(params);

  const normalized = (() => {
    if (!data) return null;

    // Backward-compatible adapter: support both legacy web shape and current backend/controller shape.
    const isLegacy = typeof (data as any).assets === 'object' && (data as any).assets != null;
    const backend = data as any;

    const sectionFromLegacy = (k: 'assets' | 'liabilities' | 'equity') => {
      const s = (data as any)[k] ?? {};
      const lines = Array.isArray(s.lines) ? s.lines : [];
      const total = typeof s.total === 'number' ? s.total : 0;
      return {
        lines: lines.map((l: any) => ({
          account_id: l.account_id ?? null,
          code: l.code ?? null,
          name: l.name ?? '—',
          amount: typeof l.amount === 'number' ? l.amount : 0,
        })),
        total,
      };
    };

    const sectionFromBackend = (k: 'assets' | 'liabilities' | 'equity') => {
      const rawLines = backend?.sections?.[k];
      const lines = Array.isArray(rawLines) ? rawLines : [];
      const totals = backend?.totals ?? {};
      const total =
        k === 'assets'
          ? (typeof totals.assets_total === 'number' ? totals.assets_total : 0)
          : k === 'liabilities'
            ? (typeof totals.liabilities_total === 'number' ? totals.liabilities_total : 0)
            : (typeof totals.equity_total === 'number' ? totals.equity_total : 0);
      return {
        lines: lines.map((l: any) => ({
          account_id: l.account_id ?? null,
          code: l.account_code ?? l.code ?? null,
          name: l.account_name ?? l.name ?? '—',
          amount: typeof l.net === 'number' ? l.net : (typeof l.amount === 'number' ? l.amount : 0),
        })),
        total,
      };
    };

    const sections = {
      assets: isLegacy ? sectionFromLegacy('assets') : sectionFromBackend('assets'),
      liabilities: isLegacy ? sectionFromLegacy('liabilities') : sectionFromBackend('liabilities'),
      equity: isLegacy ? sectionFromLegacy('equity') : sectionFromBackend('equity'),
    };

    const equationDiff =
      typeof (data as any).checks?.equation_diff === 'number'
        ? (data as any).checks.equation_diff
        : typeof backend?.totals?.assets_total === 'number' && typeof backend?.totals?.liabilities_plus_equity_total === 'number'
          ? backend.totals.assets_total - backend.totals.liabilities_plus_equity_total
          : null;

    return {
      as_of: (data as any).as_of ?? backend?.meta?.as_of ?? asOf,
      sections,
      equationDiff,
      compare: (data as any).compare,
    };
  })();

  return (
    <ReportPage>
      <PageHeader
        title={term('balanceSheet')}
        backTo="/app/reports"
        breadcrumbs={[{ label: 'Profit & Reports', to: '/app/reports' }, { label: term('balanceSheet') }]}
        right={<ReportKindBadge kind="accounting" />}
      />

      <ReportMetadataBlock asOfDate={formatDate(asOf)} />

      <ReportFilterCard>
        <FilterBar>
          <FilterGrid className="lg:grid-cols-4 xl:grid-cols-4">
            <FilterField label="As of date">
              <input type="date" value={asOf} onChange={(e) => setAsOf(e.target.value)} />
            </FilterField>
            <div className="sm:pt-7">
              <FilterCheckboxField
                id="compare-asof"
                label="Compare as-of"
                checked={compareEnabled}
                onChange={(checked) => {
                  setCompareEnabled(checked);
                  if (!checked) setCompareAsOf('');
                }}
              />
            </div>
            {compareEnabled && (
              <FilterField label="Compare as of">
                <input type="date" value={compareAsOf} onChange={(e) => setCompareAsOf(e.target.value)} />
              </FilterField>
            )}
          </FilterGrid>
        </FilterBar>
      </ReportFilterCard>

      {isLoading && <ReportLoadingState label="Loading balance sheet..." />}
      {error && <ReportErrorState error={error} />}

      {normalized && !isLoading && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">As of</div>
              <div className="font-medium">{normalized.as_of}</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">Equation check</div>
              {typeof normalized.equationDiff === 'number' ? (
                <div
                  className={`font-medium tabular-nums ${
                    Math.abs(normalized.equationDiff) <= 0.01 ? 'text-green-700' : 'text-amber-700'
                  }`}
                >
                  Assets − (Liabilities + Equity) = {formatMoney(normalized.equationDiff)}
                  {Math.abs(normalized.equationDiff) <= 0.01 && ' ✓'}
                </div>
              ) : (
                <div className="font-medium text-gray-500">
                  Equation check unavailable
                </div>
              )}
            </div>
          </div>

          {(['assets', 'liabilities', 'equity'] as const).map((key) => {
            const section = normalized.sections[key];
            return (
              <ReportSectionCard key={key}>
                <div className="px-4 py-3 bg-[#E6ECEA] font-medium text-gray-800 capitalize">{key}</div>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                      <tr>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                        <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200">
                      {(section.lines ?? []).length === 0 ? (
                        <ReportEmptyState colSpan={3} message={EMPTY_COPY.noRecords} />
                      ) : (
                        (section.lines ?? []).map((line: BalanceSheetLineRow, idx: number) => (
                          <tr key={line.account_id ?? idx}>
                            <td className="px-4 py-2 text-sm text-gray-900">{line.code ?? '—'}</td>
                            <td className="px-4 py-2 text-sm text-gray-700">{line.name}</td>
                            <td className="px-4 py-2 text-sm text-right tabular-nums">{formatMoney(line.amount)}</td>
                          </tr>
                        ))
                      )}
                    </tbody>
                    <tfoot className="bg-gray-50 font-medium">
                      <tr>
                        <td colSpan={2} className="px-4 py-2 text-sm text-gray-700">Total {key}</td>
                        <td className="px-4 py-2 text-sm text-right tabular-nums">{formatMoney(section.total ?? 0)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </ReportSectionCard>
            );
          })}

          {normalized.compare && (
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm font-medium text-gray-700 mb-2">Compare as of: {normalized.compare.as_of}</div>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 sm:gap-4 text-sm">
                <span className="text-gray-600">Assets: {formatMoney(normalized.compare.total_assets)}</span>
                <span className="text-gray-600">Liabilities: {formatMoney(normalized.compare.total_liabilities)}</span>
                <span className="text-gray-600">Equity: {formatMoney(normalized.compare.total_equity)}</span>
              </div>
            </div>
          )}
        </>
      )}
    </ReportPage>
  );
}
