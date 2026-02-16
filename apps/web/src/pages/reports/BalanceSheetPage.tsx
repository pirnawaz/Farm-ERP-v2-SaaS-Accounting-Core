import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useBalanceSheet } from '../../hooks/useReports';

const defaultAsOf = () => new Date().toISOString().split('T')[0];

export default function BalanceSheetPage() {
  const { formatMoney } = useFormatting();
  const [asOf, setAsOf] = useState(defaultAsOf());
  const [compareEnabled, setCompareEnabled] = useState(false);
  const [compareAsOf, setCompareAsOf] = useState('');

  const params = {
    as_of: asOf,
    ...(compareEnabled && compareAsOf ? { compare_as_of: compareAsOf } : {}),
  };
  const { data, isLoading, error } = useBalanceSheet(params);

  return (
    <div className="space-y-6">
      <PageHeader
        title="Balance Sheet"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Balance Sheet' }]}
      />

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">As of date</label>
            <input
              type="date"
              value={asOf}
              onChange={(e) => setAsOf(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div className="flex items-end">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={compareEnabled}
                onChange={(e) => setCompareEnabled(e.target.checked)}
                className="rounded border-gray-300"
              />
              <span className="text-sm text-gray-700">Compare as-of</span>
            </label>
          </div>
          {compareEnabled && (
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Compare as of</label>
              <input
                type="date"
                value={compareAsOf}
                onChange={(e) => setCompareAsOf(e.target.value)}
                className="w-full border border-gray-300 rounded px-3 py-2"
              />
            </div>
          )}
        </div>
      </div>

      {isLoading && (
        <div className="bg-white rounded-lg shadow p-8 text-center text-gray-500">Loading…</div>
      )}
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
          {error.message}
        </div>
      )}

      {data && !isLoading && (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">As of</div>
              <div className="font-medium">{data.as_of}</div>
            </div>
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm text-gray-500">Equation check</div>
              <div className={`font-medium tabular-nums ${Math.abs(data.checks.equation_diff) <= 0.01 ? 'text-green-700' : 'text-amber-700'}`}>
                Assets − (Liabilities + Equity) = {formatMoney(data.checks.equation_diff)}
                {Math.abs(data.checks.equation_diff) <= 0.01 && ' ✓'}
              </div>
            </div>
          </div>

          {(['assets', 'liabilities', 'equity'] as const).map((key) => {
            const section = data[key];
            return (
              <div key={key} className="bg-white rounded-lg shadow overflow-hidden">
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
                      {section.lines.length === 0 ? (
                        <tr>
                          <td colSpan={3} className="px-4 py-3 text-center text-gray-500 text-sm">
                            No lines
                          </td>
                        </tr>
                      ) : (
                        section.lines.map((line, idx) => (
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
                        <td className="px-4 py-2 text-sm text-right tabular-nums">{formatMoney(section.total)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            );
          })}

          {data.compare && (
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm font-medium text-gray-700 mb-2">Compare as of: {data.compare.as_of}</div>
              <div className="grid grid-cols-3 gap-4 text-sm">
                <span className="text-gray-600">Assets: {formatMoney(data.compare.total_assets)}</span>
                <span className="text-gray-600">Liabilities: {formatMoney(data.compare.total_liabilities)}</span>
                <span className="text-gray-600">Equity: {formatMoney(data.compare.total_equity)}</span>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
