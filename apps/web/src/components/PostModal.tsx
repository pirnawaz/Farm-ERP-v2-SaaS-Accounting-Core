import { useState } from 'react'
import { apiClient } from '@farm-erp/shared'

interface PostModalProps {
  entryId: string
  isOpen: boolean
  onClose: () => void
  onSuccess: (postingGroupId: string) => void
}

export function PostModal({ entryId, isOpen, onClose, onSuccess }: PostModalProps) {
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!isOpen) return null

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError(null)

    try {
      const postingGroup = await apiClient.post<{ id: string }>(
        `/api/daily-book-entries/${entryId}/post`,
        { posting_date: postingDate }
      )
      onSuccess(postingGroup.id)
      onClose()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to post entry')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white rounded-xl shadow-lg p-6 max-w-md w-full">
        <h2 className="text-2xl font-bold mb-4">Post Entry</h2>
        
        {error && (
          <div className="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
            <p className="text-red-800 text-sm">{error}</p>
          </div>
        )}

        <div className="bg-[#C9A24D]/10 border border-[#C9A24D]/30 rounded-lg p-3 mb-4">
          <p className="text-sm text-[#2D3A3A]">
            <strong>Warning:</strong> Posting is irreversible. This will create accounting entries that cannot be modified. Only reversal is allowed.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Posting Date *
            </label>
            <input
              type="date"
              required
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-[#1F6F5C] focus:border-[#1F6F5C]"
            />
            <p className="text-xs text-gray-500 mt-1">
              The posting date must be within the project's crop cycle date range.
            </p>
          </div>

          <div className="flex justify-end space-x-4 pt-4">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {loading ? 'Posting...' : 'Post'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
