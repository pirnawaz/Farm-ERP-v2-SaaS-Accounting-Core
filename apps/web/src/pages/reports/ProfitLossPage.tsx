import { useState } from 'react';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useProfitLoss } from '../../hooks/useReports';

const defaultFrom = () => {
  const d = new Date();
  d.setMonth(d.getMonth() - 1);
  return d.toISOString().split('T')[0];
};
const defaultTo = () => new Date().toISOString().split('T')[0];

export default function ProfitLossPage() {
  const { formatMoney } = useFormatting();
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
    <div className="space-y-6">
      <PageHeader
        title="Profit &amp; Loss"
        breadcrumbs={[{ label: 'Reports', to: '/app/reports' }, { label: 'Profit & Loss' }]}
      />

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
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
              <span className="text-sm text-gray-700">Compare period</span>
            </label>
          </div>
          {compareEnabled && (
            <>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Compare from</label>
                <input
                  type="date"
                  value={compareFrom}
                  onChange={(e) => setCompareFrom(e.target.value)}
                  className="w-full border border-gray-300 rounded px-3 py-2"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Compare to</label>
                <input
                  type="date"
                  value={compareTo}
                  onChange={(e) => setCompareTo(e.target.value)}
                  className="w-full border border-gray-300 rounded px-3 py-2"
                />
              </div>
            </>
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
            <div key={section.key} className="bg-white rounded-lg shadow overflow-hidden">
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
                      <tr>
                        <td colSpan={data.compare ? 5 : 3} className="px-4 py-3 text-center text-gray-500 text-sm">
                          No lines
                        </td>
                      </tr>
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
            </div>
          ))}

          {data.compare && (
            <div className="bg-white rounded-lg shadow p-4">
              <div className="text-sm font-medium text-gray-700 mb-2">Compare period: {data.compare.from} to {data.compare.to}</div>
              <div className="flex gap-4">
                <span className="text-sm text-gray-600">Compare net profit: {formatMoney(data.compare.net_profit)}</span>
                <span className="text-sm text-gray-600">Delta: {formatMoney(data.compare.delta)}</span>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
