import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { DailyBookEntry, Project, apiClient } from '@farm-erp/shared'
import { PostModal } from '../components/PostModal'
import { useFormatting } from '../hooks/useFormatting'

function DailyBookEntriesPage() {
  const { formatMoney, formatDate } = useFormatting()
  const navigate = useNavigate()
  const [entries, setEntries] = useState<DailyBookEntry[]>([])
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [postModalOpen, setPostModalOpen] = useState(false)
  const [selectedEntryId, setSelectedEntryId] = useState<string | null>(null)
  
  const [filters, setFilters] = useState({
    project_id: '',
    type: '',
    from: '',
    to: '',
  })

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true)
        setError(null)
        
        // Fetch projects for filter dropdown
        const projectsData = await apiClient.get<Project[]>('/api/projects')
        setProjects(projectsData)
        
        // Fetch entries with filters
        const params = new URLSearchParams()
        if (filters.project_id) params.append('project_id', filters.project_id)
        if (filters.type) params.append('type', filters.type)
        if (filters.from) params.append('from', filters.from)
        if (filters.to) params.append('to', filters.to)
        
        const entriesData = await apiClient.get<DailyBookEntry[]>(
          `/api/daily-book-entries?${params.toString()}`
        )
        setEntries(entriesData)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch data')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [filters])

  const handleDelete = async (id: string) => {
    if (!confirm('Are you sure you want to delete this entry?')) return
    
    try {
      await apiClient.delete(`/api/daily-book-entries/${id}`)
      setEntries(entries.filter(e => e.id !== id))
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to delete entry')
    }
  }

  const handlePostClick = (id: string) => {
    setSelectedEntryId(id)
    setPostModalOpen(true)
  }

  const handlePostSuccess = (postingGroupId: string) => {
    // Refresh entries to show updated status
    const fetchData = async () => {
      try {
        const params = new URLSearchParams()
        if (filters.project_id) params.append('project_id', filters.project_id)
        if (filters.type) params.append('type', filters.type)
        if (filters.from) params.append('from', filters.from)
        if (filters.to) params.append('to', filters.to)
        
        const entriesData = await apiClient.get<DailyBookEntry[]>(
          `/api/daily-book-entries?${params.toString()}`
        )
        setEntries(entriesData)
      } catch (err) {
        // Silently fail refresh
      }
    }
    fetchData()
    
    // Navigate to posting group detail
    navigate(`/posting-groups/${postingGroupId}`)
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Daily Book Entries</h2>
        <Link
          to="/daily-book-entries/new"
          className="bg-[#1F6F5C] text-white px-4 py-2 rounded hover:bg-[#1a5a4a]"
        >
          New Entry
        </Link>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-lg shadow p-4">
        <h3 className="font-semibold mb-3">Filters</h3>
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
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
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Type
            </label>
            <select
              value={filters.type}
              onChange={(e) => setFilters({ ...filters, type: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2"
            >
              <option value="">All Types</option>
              <option value="EXPENSE">Expense</option>
              <option value="INCOME">Income</option>
            </select>
          </div>
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
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-4">
          <p className="text-red-800">Error: {error}</p>
        </div>
      )}

      {/* Loading */}
      {loading && <p className="text-gray-600">Loading entries...</p>}

      {/* Entries Table */}
      {!loading && !error && (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Date
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Type
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Description
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Amount
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {entries.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-4 text-center text-gray-500">
                    No entries found
                  </td>
                </tr>
              ) : (
                entries.map((entry) => (
                  <tr key={entry.id}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {formatDate(entry.event_date)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span
                        className={`px-2 py-1 text-xs font-semibold rounded ${
                          entry.type === 'INCOME'
                            ? 'bg-green-100 text-green-800'
                            : 'bg-red-100 text-red-800'
                        }`}
                      >
                        {entry.type}
                      </span>
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-900">
                      {entry.description}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      <span className="tabular-nums">{formatMoney(entry.gross_amount, { currencyCode: entry.currency_code })}</span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="px-2 py-1 text-xs font-semibold rounded bg-gray-100 text-gray-800">
                        {entry.status}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      {entry.status === 'DRAFT' && (
                        <>
                          <Link
                            to={`/daily-book-entries/${entry.id}/edit`}
                            className="text-[#1F6F5C] hover:text-[#1a5a4a] mr-4"
                          >
                            Edit
                          </Link>
                          <button
                            onClick={() => handlePostClick(entry.id)}
                            className="text-green-600 hover:text-green-900 mr-4"
                          >
                            Post
                          </button>
                          <button
                            onClick={() => handleDelete(entry.id)}
                            className="text-red-600 hover:text-red-900"
                          >
                            Delete
                          </button>
                        </>
                      )}
                      {entry.status === 'POSTED' && (
                        <span className="text-gray-500 text-xs">Posted</span>
                      )}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      )}

      {/* Post Modal */}
      {selectedEntryId && (
        <PostModal
          entryId={selectedEntryId}
          isOpen={postModalOpen}
          onClose={() => {
            setPostModalOpen(false)
            setSelectedEntryId(null)
          }}
          onSuccess={handlePostSuccess}
        />
      )}
    </div>
  )
}

export default DailyBookEntriesPage
