import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { DailyBookEntry, Project, apiClient } from '@farm-erp/shared'
import { useTenantSettings } from '../hooks/useTenantSettings'

function DailyBookEntryFormPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const isEdit = !!id
  const { settings } = useTenantSettings()

  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [entryStatus, setEntryStatus] = useState<'DRAFT' | 'POSTED' | 'VOID' | null>(null)

  const [formData, setFormData] = useState({
    project_id: '',
    type: 'EXPENSE',
    event_date: new Date().toISOString().split('T')[0],
    description: '',
    gross_amount: '',
    currency_code: '',
  })

  useEffect(() => {
    if (!id && settings?.currency_code) {
      setFormData((prev) => (prev.currency_code === '' ? { ...prev, currency_code: settings.currency_code } : prev))
    }
  }, [id, settings?.currency_code])

  useEffect(() => {
    const fetchData = async () => {
      try {
        // Fetch projects
        const projectsData = await apiClient.get<Project[]>('/api/projects')
        setProjects(projectsData)

        // If editing, fetch entry data
        if (id) {
          const entry = await apiClient.get<DailyBookEntry>(`/api/daily-book-entries/${id}`)
          setEntryStatus(entry.status)
          setFormData({
            project_id: entry.project_id,
            type: entry.type,
            event_date: entry.event_date,
            description: entry.description,
            gross_amount: entry.gross_amount.toString(),
            currency_code: entry.currency_code,
          })
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch data')
      }
    }

    fetchData()
  }, [id])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError(null)

    try {
      const payload = {
        ...formData,
        gross_amount: parseFloat(formData.gross_amount),
      }

      if (isEdit) {
        await apiClient.patch(`/api/daily-book-entries/${id}`, payload)
      } else {
        await apiClient.post('/api/daily-book-entries', payload)
      }

      navigate('/daily-book-entries')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save entry')
    } finally {
      setLoading(false)
    }
  }

  const isReadOnly = isEdit && entryStatus === 'POSTED'

  return (
    <div className="bg-white rounded-lg shadow p-6 max-w-2xl">
      <h2 className="text-2xl font-bold mb-6">
        {isEdit ? (isReadOnly ? 'View Entry (Posted)' : 'Edit Entry') : 'New Entry'}
      </h2>

      {isReadOnly && (
        <div className="bg-yellow-50 border border-yellow-200 rounded p-4 mb-4">
          <p className="text-yellow-800 text-sm">
            This entry has been posted and cannot be edited or deleted.
          </p>
        </div>
      )}

      {error && (
        <div className="bg-red-50 border border-red-200 rounded p-4 mb-4">
          <p className="text-red-800">{error}</p>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Project *
          </label>
          <select
            required
            disabled={isReadOnly}
            value={formData.project_id}
            onChange={(e) => setFormData({ ...formData, project_id: e.target.value })}
            className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
          >
            <option value="">Select a project</option>
            {projects.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Type *
          </label>
          <select
            required
            disabled={isReadOnly}
            value={formData.type}
            onChange={(e) => setFormData({ ...formData, type: e.target.value as 'EXPENSE' | 'INCOME' })}
            className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
          >
            <option value="EXPENSE">Expense</option>
            <option value="INCOME">Income</option>
          </select>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Event Date *
          </label>
          <input
            type="date"
            required
            disabled={isReadOnly}
            value={formData.event_date}
            onChange={(e) => setFormData({ ...formData, event_date: e.target.value })}
            className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Description *
          </label>
          <input
            type="text"
            required
            disabled={isReadOnly}
            value={formData.description}
            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
            maxLength={255}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Amount *
            </label>
              <input
              type="number"
              required
              step="0.01"
              min="0"
              disabled={isReadOnly}
              value={formData.gross_amount}
              onChange={(e) => setFormData({ ...formData, gross_amount: e.target.value })}
              className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Currency
            </label>
            <input
              type="text"
              disabled={isReadOnly}
              value={formData.currency_code}
              onChange={(e) => setFormData({ ...formData, currency_code: e.target.value.toUpperCase().slice(0, 3) })}
              className="w-full border border-gray-300 rounded px-3 py-2 disabled:bg-gray-100 disabled:cursor-not-allowed"
              maxLength={3}
            />
          </div>
        </div>

        <div className="flex justify-end space-x-4 pt-4">
          <button
            type="button"
            onClick={() => navigate('/daily-book-entries')}
            className="px-4 py-2 border border-gray-300 rounded text-gray-700 hover:bg-gray-50"
          >
            {isReadOnly ? 'Back' : 'Cancel'}
          </button>
          {!isReadOnly && (
            <button
              type="submit"
              disabled={loading}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {loading ? 'Saving...' : isEdit ? 'Update' : 'Create'}
            </button>
          )}
        </div>
      </form>
    </div>
  )
}

export default DailyBookEntryFormPage
