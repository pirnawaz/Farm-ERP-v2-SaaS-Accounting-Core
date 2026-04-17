import { useEffect, useState } from 'react'
import { ProjectPLRow, Project, apiClient } from '@farm-erp/shared'
import { exportToCSV } from '../utils/csvExport'
import { exportAmountForSpreadsheet } from '../utils/exportFormatting'
import { metaReportingPeriodLabel } from '../utils/reportPresentation'
import { useFormatting } from '../hooks/useFormatting'
import { useLocalisation } from '../hooks/useLocalisation'
import { useTenantSettings } from '../hooks/useTenantSettings'
import { useCropCycles } from '../hooks/useCropCycles'
import { EMPTY_COPY, REPORT_LABELS } from '../config/presentation'
import { PrintableReport } from '../components/print/PrintableReport'
import { ReportMetadataBlock } from '../components/report/ReportMetadataBlock'
import { ReportErrorState, ReportLoadingState } from '../components/report'
import { terravaBaseExportMetadataRows } from '../utils/reportPageMetadata'
import { term } from '../config/terminology'
import { PageContainer } from '../components/PageContainer'
import { FilterBar, FilterField, FilterGrid } from '../components/FilterBar'

function ProjectPLPage() {
  const { formatMoney, formatDateRange } = useFormatting()
  const { settings } = useTenantSettings()
  const { currency_code, locale, timezone } = useLocalisation()
  const [data, setData] = useState<ProjectPLRow[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  
  const [filters, setFilters] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    project_id: '',
    crop_cycle_id: '',
  })
  const { data: cropCycles = [] } = useCropCycles()

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
      if (!filters.from || !filters.to) return
      
      try {
        setLoading(true)
        setError(null)
        
        const result = await apiClient.getProjectPL({
          from: filters.from,
          to: filters.to,
          project_id: filters.project_id || undefined,
          crop_cycle_id: filters.crop_cycle_id || undefined,
        })
        setData(result)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch project P&L')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [filters])

  const handleExport = () => {
    const mapped = data.map((row) => ({
      project_id: row.project_id,
      currency_code: row.currency_code,
      income: exportAmountForSpreadsheet(row.income),
      expenses: exportAmountForSpreadsheet(row.expenses),
      net_profit: exportAmountForSpreadsheet(row.net_profit),
    }))
    const headers = ['project_id', 'currency_code', 'income', 'expenses', 'net_profit']
    exportToCSV(mapped, '', headers, {
      reportName: 'ProjectPL',
      fromDate: filters.from,
      toDate: filters.to,
      metadataRows: terravaBaseExportMetadataRows({
        reportExportName: 'Terrava Field Cycle P&L',
        baseCurrency: currency_code || settings?.currency_code || 'PKR',
        period: { mode: 'range', from: filters.from, to: filters.to },
        locale,
        timezone,
      }),
    })
  }

  // Calculate totals
  const totals = data.reduce(
    (acc, row) => ({
      income: acc.income + parseFloat(row.income),
      expenses: acc.expenses + parseFloat(row.expenses),
      net_profit: acc.net_profit + parseFloat(row.net_profit),
    }),
    { income: 0, expenses: 0, net_profit: 0 }
  )

  return (
    <PageContainer className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between no-print">
        <h2 className="text-2xl font-bold">{term('fieldCycle')} P&amp;L</h2>
        <div className="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
          <button
            onClick={() => window.print()}
            className="w-full sm:w-auto bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] text-sm font-medium"
          >
            Print
          </button>
          <button
            onClick={handleExport}
            disabled={data.length === 0}
            className="w-full sm:w-auto bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a] disabled:bg-gray-400 disabled:cursor-not-allowed text-sm font-medium"
          >
            Export CSV
          </button>
        </div>
      </div>

      <FilterBar className="no-print">
        <div className="bg-white p-4 rounded-lg shadow">
          <FilterGrid className="xl:grid-cols-4">
            <FilterField label="From Date">
              <input
                type="date"
                value={filters.from}
                onChange={(e) => setFilters({ ...filters, from: e.target.value })}
              />
            </FilterField>
            <FilterField label="To Date">
              <input
                type="date"
                value={filters.to}
                onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              />
            </FilterField>
            <FilterField label="Crop cycle">
              <select
                value={filters.crop_cycle_id}
                onChange={(e) => setFilters({ ...filters, crop_cycle_id: e.target.value })}
              >
                <option value="">All seasons</option>
                {cropCycles.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </FilterField>
            <FilterField label={term('fieldCycle')}>
              <select
                value={filters.project_id}
                onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              >
                <option value="">{`All ${term('fieldCycles')}`}</option>
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </FilterField>
          </FilterGrid>
        </div>
      </FilterBar>

      <div className="no-print">
        <ReportMetadataBlock reportingPeriodRange={formatDateRange(filters.from, filters.to)} />
      </div>

      {loading && <ReportLoadingState label={`Loading ${term('fieldCycle').toLowerCase()} P&L...`} className="no-print" />}
      {error && <ReportErrorState error={error} className="no-print" />}

      {!loading && !error && (
        <>
          {/* Screen view */}
          <div className="bg-white rounded-lg shadow overflow-hidden no-print">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-[#E6ECEA]">
                  <tr>
                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      {term('fieldCycle')}
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Currency
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Income
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Expenses
                    </th>
                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Net Profit
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {data.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-3 sm:px-6 py-3 sm:py-4 text-center text-gray-500">
                        No activity found for this period.
                      </td>
                    </tr>
                  ) : (
                    <>
                      {data.map((row) => {
                        const project = projects.find(p => p.id === row.project_id)
                        const label = row.project_name ?? project?.name ?? (row.project_id ? row.project_id.substring(0, 8) + '...' : 'N/A')
                        return (
                          <tr key={`${row.project_id}-${row.currency_code}`}>
                            <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-normal break-words text-sm font-medium text-gray-900">
                              {label}
                            </td>
                            <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-500">
                              {row.currency_code}
                            </td>
                            <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                              <span className="tabular-nums">{formatMoney(row.income)}</span>
                            </td>
                            <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                              <span className="tabular-nums">{formatMoney(row.expenses)}</span>
                            </td>
                            <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                              <span className="tabular-nums">{formatMoney(row.net_profit)}</span>
                            </td>
                          </tr>
                        )
                      })}
                      {data.length > 0 && (
                        <tr className="totals-row bg-gray-50 font-semibold">
                          <td colSpan={2} className="px-3 sm:px-6 py-3 sm:py-4 text-sm text-gray-900">
                            {REPORT_LABELS.total}
                          </td>
                          <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(totals.income)}</span>
                          </td>
                          <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(totals.expenses)}</span>
                          </td>
                          <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(totals.net_profit)}</span>
                          </td>
                        </tr>
                      )}
                    </>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Print view */}
          <PrintableReport
            title={`${term('fieldCycle')} P&L`}
            metaLeft={metaReportingPeriodLabel(formatDateRange(filters.from, filters.to))}
          >
            <table className="w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {term('fieldCycle')}
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Currency
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Income
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Expenses
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Net Profit
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {data.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-4 text-center text-gray-500">
                      {EMPTY_COPY.noDataForPeriod}
                    </td>
                  </tr>
                ) : (
                  <>
                    {data.map((row) => {
                      const project = projects.find(p => p.id === row.project_id)
                      const printLabel = row.project_name ?? project?.name ?? (row.project_id ? row.project_id.substring(0, 8) + '...' : 'N/A')
                      return (
                        <tr key={`${row.project_id}-${row.currency_code}`}>
                          <td className="px-6 py-4 text-sm font-medium text-gray-900">
                            {printLabel}
                          </td>
                          <td className="px-6 py-4 text-sm text-gray-500">
                            {row.currency_code}
                          </td>
                          <td className="px-6 py-4 text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.income)}</span>
                          </td>
                          <td className="px-6 py-4 text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.expenses)}</span>
                          </td>
                          <td className="px-6 py-4 text-sm font-medium text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.net_profit)}</span>
                          </td>
                        </tr>
                      )
                    })}
                    {data.length > 0 && (
                      <tr className="print-total-row totals-row bg-gray-50 font-semibold print-avoid-break">
                        <td colSpan={2} className="px-6 py-4 text-sm text-gray-900">
                          {REPORT_LABELS.total}
                        </td>
                        <td className="px-6 py-4 text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(totals.income)}</span>
                        </td>
                        <td className="px-6 py-4 text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(totals.expenses)}</span>
                        </td>
                        <td className="px-6 py-4 text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(totals.net_profit)}</span>
                        </td>
                      </tr>
                    )}
                  </>
                )}
              </tbody>
            </table>
          </PrintableReport>
        </>
      )}
    </PageContainer>
  )
}

export default ProjectPLPage
