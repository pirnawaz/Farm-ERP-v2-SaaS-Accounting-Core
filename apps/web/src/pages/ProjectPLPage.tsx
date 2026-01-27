import { useEffect, useState } from 'react'
import { ProjectPLRow, Project, apiClient } from '@farm-erp/shared'
import { exportToCSV } from '../utils/csvExport'
import { useFormatting } from '../hooks/useFormatting'

function ProjectPLPage() {
  const { formatMoney } = useFormatting()
  const [data, setData] = useState<ProjectPLRow[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  
  const [filters, setFilters] = useState({
    from: new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0],
    to: new Date().toISOString().split('T')[0],
    project_id: '',
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
      if (!filters.from || !filters.to) return
      
      try {
        setLoading(true)
        setError(null)
        
        const result = await apiClient.getProjectPL({
          from: filters.from,
          to: filters.to,
          project_id: filters.project_id || undefined,
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
    exportToCSV(
      data,
      `project-pl-${filters.from}-to-${filters.to}.csv`,
      ['project_id', 'currency_code', 'income', 'expenses', 'net_profit']
    )
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
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Project P&L</h2>
        <button
          onClick={handleExport}
          disabled={data.length === 0}
          className="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
        >
          Export CSV
        </button>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              From Date
            </label>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => setFilters({ ...filters, from: e.target.value })}
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
              onChange={(e) => setFilters({ ...filters, to: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Project
            </label>
            <select
              value={filters.project_id}
              onChange={(e) => setFilters({ ...filters, project_id: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Projects</option>
              {projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {loading && <div className="text-center py-8">Loading...</div>}
      {error && <div className="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">{error}</div>}

      {!loading && !error && (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Project
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
                      No data found
                    </td>
                  </tr>
                ) : (
                  <>
                    {data.map((row) => {
                      const project = projects.find(p => p.id === row.project_id)
                      return (
                        <tr key={`${row.project_id}-${row.currency_code}`}>
                          <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            {project?.name || row.project_id.substring(0, 8) + '...'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {row.currency_code}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.income)}</span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.expenses)}</span>
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                            <span className="tabular-nums">{formatMoney(row.net_profit)}</span>
                          </td>
                        </tr>
                      )
                    })}
                    {data.length > 0 && (
                      <tr className="bg-gray-50 font-semibold">
                        <td colSpan={2} className="px-6 py-4 text-sm text-gray-900">
                          Total
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(totals.income)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                          <span className="tabular-nums">{formatMoney(totals.expenses)}</span>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
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
      )}
    </div>
  )
}

export default ProjectPLPage
