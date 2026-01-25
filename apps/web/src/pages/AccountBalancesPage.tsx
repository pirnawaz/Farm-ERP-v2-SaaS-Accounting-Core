import { useEffect, useState } from 'react'
import { AccountBalanceRow, Project, apiClient } from '@farm-erp/shared'
import { exportToCSV } from '../utils/csvExport'
import { useFormatting } from '../hooks/useFormatting'

function AccountBalancesPage() {
  const { formatMoney } = useFormatting()
  const [data, setData] = useState<AccountBalanceRow[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  
  const [filters, setFilters] = useState({
    as_of: new Date().toISOString().split('T')[0],
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
      if (!filters.as_of) return
      
      try {
        setLoading(true)
        setError(null)
        
        const result = await apiClient.getAccountBalances({
          as_of: filters.as_of,
          project_id: filters.project_id || undefined,
        })
        setData(result)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch account balances')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [filters])

  const handleExport = () => {
    exportToCSV(
      data,
      `account-balances-as-of-${filters.as_of}.csv`,
      ['account_code', 'account_name', 'account_type', 'currency_code', 'debits', 'credits', 'balance']
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Account Balances</h2>
        <button
          onClick={handleExport}
          disabled={data.length === 0}
          className="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
        >
          Export CSV
        </button>
      </div>

      <div className="bg-white p-4 rounded-lg shadow space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              As Of Date
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
              <thead className="bg-gray-50">
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
                    Debits
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Credits
                  </th>
                  <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Balance
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {data.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-6 py-4 text-center text-gray-500">
                      No data found
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
                        {formatMoney(row.debits)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                        {formatMoney(row.credits)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-right">
                        {formatMoney(row.balance)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}

export default AccountBalancesPage
