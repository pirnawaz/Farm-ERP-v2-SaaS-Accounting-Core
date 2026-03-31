import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { GeneralLedgerLine, Project, Account, apiClient } from '@farm-erp/shared'
import { exportToCSV } from '../utils/csvExport'
import { exportAmountForSpreadsheet, exportDateIsoYmd } from '../utils/exportFormatting'
import { metaReportingPeriodLabel } from '../utils/reportPresentation'
import { useFormatting } from '../hooks/useFormatting'
import { useLocalisation } from '../hooks/useLocalisation'
import { terravaBaseExportMetadataRows } from '../utils/reportPageMetadata'
import { EMPTY_COPY } from '../config/presentation'
import { PrintableReport } from '../components/print/PrintableReport'
import { PageHeader } from '../components/PageHeader'
import { Term } from '../components/Term'
import { term } from '../config/terminology'
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock'
import { ReportErrorState, ReportLoadingState } from '../components/report'

const defaultFrom = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
const defaultTo = new Date().toISOString().split('T')[0]

function GeneralLedgerPage() {
  const [searchParams] = useSearchParams()
  const { formatMoney, formatDate, formatDateRange } = useFormatting()
  const { currency_code, locale, timezone } = useLocalisation()
  const [data, setData] = useState<GeneralLedgerLine[]>([])
  const [pagination, setPagination] = useState({ page: 1, per_page: 50, total: 0, last_page: 1 })
  const [projects, setProjects] = useState<Project[]>([])
  const [accounts, setAccounts] = useState<Account[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const fromParam = searchParams.get('from') ?? defaultFrom
  const toParam = searchParams.get('to') ?? defaultTo
  const projectIdParam = searchParams.get('project_id') ?? ''

  const [filters, setFilters] = useState({
    from: fromParam,
    to: toParam,
    account_id: '',
    project_id: projectIdParam,
    page: 1,
    per_page: 50,
  })

  useEffect(() => {
    setFilters((prev) => ({
      ...prev,
      from: fromParam,
      to: toParam,
      project_id: projectIdParam,
      page: 1,
    }))
  }, [fromParam, toParam, projectIdParam])

  useEffect(() => {
    const fetchOptions = async () => {
      try {
        const [projectsData, accountsData] = await Promise.all([
          apiClient.get<Project[]>('/api/projects'),
          apiClient.get<Account[]>('/api/accounts').catch(() => []), // Accounts endpoint may not exist
        ])
        setProjects(projectsData)
        setAccounts(accountsData)
      } catch (err) {
        console.error('Failed to fetch options', err)
      }
    }
    fetchOptions()
  }, [])

  useEffect(() => {
    const fetchData = async () => {
      if (!filters.from || !filters.to) return
      
      try {
        setLoading(true)
        setError(null)
        
        const result = await apiClient.getGeneralLedger({
          from: filters.from,
          to: filters.to,
          account_id: filters.account_id || undefined,
          project_id: filters.project_id || undefined,
          page: filters.page,
          per_page: filters.per_page,
        })
        setData(result.data)
        setPagination(result.pagination)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch general ledger')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [filters])

  const handleExport = () => {
    // Export all data (not just current page)
    const exportData = async () => {
      try {
        const result = await apiClient.getGeneralLedger({
          from: filters.from,
          to: filters.to,
          account_id: filters.account_id || undefined,
          project_id: filters.project_id || undefined,
          page: 1,
          per_page: 10000, // Large number to get all
        })
        const mapped = result.data.map((row) => ({
          posting_date: exportDateIsoYmd(row.posting_date),
          posting_group_id: row.posting_group_id,
          account_code: row.account_code,
          account_name: row.account_name,
          debit: exportAmountForSpreadsheet(row.debit),
          credit: exportAmountForSpreadsheet(row.credit),
          net: exportAmountForSpreadsheet(row.net),
          source_type: row.source_type,
          source_id: row.source_id,
        }))
        const glHeaders = [
          'posting_date',
          'posting_group_id',
          'account_code',
          'account_name',
          'debit',
          'credit',
          'net',
          'source_type',
          'source_id',
        ]
        exportToCSV(mapped, '', glHeaders, {
          reportName: 'GeneralLedger',
          fromDate: filters.from,
          toDate: filters.to,
          metadataRows: terravaBaseExportMetadataRows({
            reportExportName: 'Terrava General Ledger',
            baseCurrency: currency_code,
            period: { mode: 'range', from: filters.from, to: filters.to },
            locale,
            timezone,
          }),
        })
      } catch (err) {
        alert('Failed to export: ' + (err instanceof Error ? err.message : 'Unknown error'))
      }
    }
    exportData()
  }

  return (
    <div className="space-y-6">
      <div className="no-print">
        <PageHeader
          title={term('generalLedger')}
          backTo="/app/reports"
          breadcrumbs={[
            { label: 'Profit & Reports', to: '/app/reports' },
            { label: term('generalLedger') },
          ]}
          right={
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => window.print()}
                className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
              >
                Print
              </button>
              <button
                type="button"
                onClick={handleExport}
                disabled={data.length === 0}
                className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
              >
                Export CSV
              </button>
            </div>
          }
        />
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4 no-print">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              From Date
            </label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value, page: 1 })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              To Date
            </label>
            <input
              type="date"
              value={filters.to}
              onChange={(e) => setFilters({ ...filters, to: e.target.value, page: 1 })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Account
            </label>
            <select
              value={filters.account_id}
              onChange={(e) => setFilters({ ...filters, account_id: e.target.value, page: 1 })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Accounts</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} - {a.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {term('fieldCycle')}
            </label>
            <select
              value={filters.project_id}
              onChange={(e) => setFilters({ ...filters, project_id: e.target.value, page: 1 })}
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
        </div>
      </div>

      <div className="no-print">
        <ReportMetadataBlock reportingPeriodRange={formatDateRange(filters.from, filters.to)} />
      </div>

      {loading && <ReportLoadingState label="Loading general ledger..." className="no-print" />}
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
                      Date
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Account
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Description
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      <Term k="postingGroup" showHint />
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
                        No activity found for this period.
                      </td>
                    </tr>
                  ) : (
                    data.map((row) => (
                      <tr key={row.ledger_entry_id}>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                          {formatDate(row.posting_date)}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          <div>
                            <div className="font-medium">{row.account_code}</div>
                            <div className="text-xs text-gray-400">{row.account_name}</div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          <div>
                            {row.reversal_of_posting_group_id && (
                              <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 mr-1">
                                REVERSAL
                              </span>
                            )}
                            <span>{row.source_type}</span>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                          {row.posting_group_id ? (
                            <Link
                              to={`/app/posting-groups/${row.posting_group_id}`}
                              className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                            >
                              {row.posting_group_id.substring(0, 8) + '...'}
                            </Link>
                          ) : (
                            'N/A'
                          )}
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.debit)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.credit)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(row.net)}</span>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Print view */}
          <PrintableReport
            title={term('generalLedger')}
            metaLeft={metaReportingPeriodLabel(formatDateRange(filters.from, filters.to))}
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Date
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Account
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Description
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {term('postingGroup')}
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
                      No activity found for this period.
                    </td>
                  </tr>
                ) : (
                  data.map((row) => (
                    <tr key={row.ledger_entry_id}>
                      <td className="px-6 py-4 text-sm text-gray-900">
                        {formatDate(row.posting_date)}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        <div>
                          <div className="font-medium">{row.account_code}</div>
                          <div className="text-xs text-gray-400">{row.account_name}</div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        <div>
                          {row.reversal_of_posting_group_id && (
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800 mr-1">
                              REVERSAL
                            </span>
                          )}
                          <span>{row.source_type}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500">
                        {row.posting_group_id ? row.posting_group_id.substring(0, 8) + '...' : 'N/A'}
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.debit)}</span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.credit)}</span>
                      </td>
                      <td className="px-6 py-4 text-sm font-medium text-gray-900 text-right">
                        <span className="tabular-nums">{formatMoney(row.net)}</span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </PrintableReport>

          {pagination.last_page > 1 && (
            <div className="flex items-center justify-between bg-white px-4 py-3 rounded-lg shadow">
              <div className="text-sm text-gray-700">
                Showing {((pagination.page - 1) * pagination.per_page) + 1} to{' '}
                {Math.min(pagination.page * pagination.per_page, pagination.total)} of{' '}
                {pagination.total} results
              </div>
              <div className="flex space-x-2">
                <button
                  onClick={() => setFilters({ ...filters, page: filters.page - 1 })}
                  disabled={filters.page <= 1}
                  className="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Previous
                </button>
                <button
                  onClick={() => setFilters({ ...filters, page: filters.page + 1 })}
                  disabled={filters.page >= pagination.last_page}
                  className="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  )
}

export default GeneralLedgerPage
