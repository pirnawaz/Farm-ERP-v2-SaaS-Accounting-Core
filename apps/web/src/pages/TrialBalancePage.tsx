import { useEffect, useState } from 'react'
import { TrialBalanceRow, TrialBalanceResponse, Project, apiClient } from '@farm-erp/shared'
import { exportToCSV } from '../utils/csvExport'
import { exportAmountForSpreadsheet } from '../utils/exportFormatting'
import { useFormatting } from '../hooks/useFormatting'
import { useLocalisation } from '../hooks/useLocalisation'
import { terravaBaseExportMetadataRows } from '../utils/reportPageMetadata'
import { PrintableReport } from '../components/print/PrintableReport'
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock'
import { ReportErrorState, ReportLoadingState } from '../components/report'
import { EMPTY_COPY, REPORT_LABELS } from '../config/presentation'
import { term } from '../config/terminology'

function TrialBalancePage() {
  const { formatMoney, formatDate } = useFormatting()
  const { currency_code, locale, timezone } = useLocalisation()
  const [data, setData] = useState<TrialBalanceRow[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  
  const [filters, setFilters] = useState({
    as_of: new Date().toISOString().split('T')[0], // Today
    project_id: '',
    currency_code: '',
  })

  useEffect(() => {
    const fetchProjects = async () => {
      try {
        const projectsData = await apiClient.get<Project[]>('/api/projects')
        setProjects(projectsData)
      } catch (err) {
        console.error('Failed to fetch projects', err)
      }
    }
    fetchProjects()
  }, [])

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.as_of) return
      
      try {
        setLoading(true)
        setError(null)
        
        const result = await apiClient.getTrialBalance({
          as_of: filters.as_of,
          project_id: filters.project_id || undefined,
          currency_code: filters.currency_code || undefined,
        }) as TrialBalanceResponse
        setData(result.rows ?? [])
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch trial balance')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [filters])

  const handleExport = () => {
    const rows = data.map((row) => ({
      account_code: row.account_code,
      account_name: row.account_name,
      account_type: row.account_type,
      currency_code: row.currency_code,
      total_debit: exportAmountForSpreadsheet(row.total_debit),
      total_credit: exportAmountForSpreadsheet(row.total_credit),
      net: exportAmountForSpreadsheet(row.net),
    }))
    const headers = ['account_code', 'account_name', 'account_type', 'currency_code', 'total_debit', 'total_credit', 'net']
    exportToCSV(rows, '', headers, {
      reportName: 'TrialBalance',
      asOfDate: filters.as_of,
      metadataRows: terravaBaseExportMetadataRows({
        reportExportName: 'Terrava Trial Balance',
        baseCurrency: currency_code,
        period: { mode: 'asOf', asOf: filters.as_of },
        locale,
        timezone,
      }),
    })
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center no-print">
        <h2 className="text-2xl font-bold" data-testid="report-heading-trial-balance">{term('trialBalance')}</h2>
        <div className="flex gap-2">
          <button
            onClick={() => window.print()}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            onClick={handleExport}
            disabled={data.length === 0}
            className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              As of
            </label>
            <input
              type="date"
              value={filters.as_of}
              onChange={(e) => setFilters({ ...filters, as_of: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {term('fieldCycle')}
            </label>
            <select
              value={filters.project_id}
              onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">{`All ${term('fieldCycles')}`}</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Currency
            </label>
            <input
              type="text"
              value={filters.currency_code}
              onChange={(e) => setFilters({ ...filters, currency_code: e.target.value.toUpperCase() })}
              placeholder={currency_code || 'PKR'}
              maxLength={3}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock asOfDate={formatDate(filters.as_of)} />
      </div>

      {loading && <ReportLoadingState label="Loading trial balance..." className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!loading && !error && (
        <>
          {/* Screen view */}
          <div className="bg-white rounded-lg shadow overflow-hidden no-print">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Account Code
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Account Name
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Type
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Currency
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Debit
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Credit
                    </th>
                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Net
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {data.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-6 py-4 text-center text-gray-500">
                        No balances found for this date.
                      </td>
                    </tr>
                  ) : (
                    data.map((row) => (
                      <tr key={`${row.account_id}-${row.currency_code}`}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                          {row.account_code}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.account_name}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.account_type}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.currency_code}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.total_debit)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.total_credit)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.net)}</span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
                {data.length > 0 && (
                  <tfoot>
                    <tr className="totals-row bg-gray-50 font-semibold">
                      <td colSpan={4} className="px-6 py-4 text-sm text-gray-900">
                        Total
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.total_debit), 0))}</span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.total_credit), 0))}</span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.net), 0))}</span>
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>

          {/* Print view */}
          <PrintableReport
            title={term('trialBalance')}
            metaLeft={`${REPORT_LABELS.asOf}: ${formatDate(filters.as_of)}`}
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Account Code
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Account Name
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Type
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Currency
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Debit
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Credit
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Net
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {data.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-4 text-center text-gray-500">
                      No balances found for this date.
                    </td>
                  </tr>
                ) : (
                  data.map((row) => (
                    <tr key={`${row.account_id}-${row.currency_code}`}>
                      <td className="px-6 py-4 text-sm font-medium text-gray-900">
                        {row.account_code}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        {row.account_name}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        {row.account_type}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        {row.currency_code}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.total_debit)}</span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.total_credit)}</span>
                      </td>
                      <td className="px-6 py-4 text-sm font-medium text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.net)}</span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
              {data.length > 0 && (
                <tfoot>
                  <tr className="print-total-row totals-row bg-gray-50 font-semibold print-avoid-break">
                    <td colSpan={4} className="px-6 py-4 text-sm text-gray-900">
                      Total
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-900 text-right">
                      <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.total_debit), 0))}</span>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-900 text-right">
                      <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.total_credit), 0))}</span>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-900 text-right">
                      <span className="tabular-nums">{formatMoney(data.reduce((sum, row) => sum + parseFloat(row.net), 0))}</span>
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </PrintableReport>
        </>
      )}
    </div>
  )
}

export default TrialBalancePage
