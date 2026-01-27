import { useState, useEffect } from 'react'
import { apiClient, DailyBookEntry } from '@farm-erp/shared'
import { useFormatting } from '../hooks/useFormatting'

interface PostModalProps {
  entryId: string
  isOpen: boolean
  onClose: () => void
  onSuccess: (postingGroupId: string) => void
}

interface PreviewLine {
  account: string
  description?: string
  debit: number
  credit: number
}

export function PostModal({ entryId, isOpen, onClose, onSuccess }: PostModalProps) {
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [entry, setEntry] = useState<DailyBookEntry | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const { formatMoney, formatDate } = useFormatting()

  // Fetch entry data when modal opens
  useEffect(() => {
    if (isOpen && entryId) {
      setPreviewLoading(true)
      apiClient.get<DailyBookEntry>(`/api/daily-book-entries/${entryId}`)
        .then(setEntry)
        .catch(() => setEntry(null))
        .finally(() => setPreviewLoading(false))
    }
  }, [isOpen, entryId])

  // Generate preview lines based on entry
  const previewLines: PreviewLine[] = entry ? (() => {
    const amount = typeof entry.gross_amount === 'string' 
      ? parseFloat(entry.gross_amount) 
      : entry.gross_amount
    
    if (entry.type === 'INCOME') {
      return [
        { account: 'Cash', description: entry.description, debit: amount, credit: 0 },
        { account: 'Project Revenue', description: entry.description, debit: 0, credit: amount },
      ]
    } else {
      // EXPENSE
      return [
        { account: 'Expense Account', description: entry.description, debit: amount, credit: 0 },
        { account: 'Cash', description: entry.description, debit: 0, credit: amount },
      ]
    }
  })() : []

  const totalDebit = previewLines.reduce((sum, line) => sum + line.debit, 0)
  const totalCredit = previewLines.reduce((sum, line) => sum + line.credit, 0)

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
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div className="bg-white rounded-xl shadow-2xl p-8 max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div className="mb-6">
          <h2 className="text-2xl font-bold text-gray-900 mb-2">Post Entry</h2>
          <p className="text-sm text-gray-600">
            Create accounting entries for this daily book entry
          </p>
        </div>
        
        {error && (
          <div className="bg-red-50 border-l-4 border-red-400 rounded-lg p-4 mb-6">
            <p className="text-red-800 text-sm font-medium">{error}</p>
          </div>
        )}

        <div className="bg-[#C9A24D]/10 border-l-4 border-[#C9A24D] rounded-lg p-4 mb-6">
          <div className="flex items-start">
            <div className="flex-shrink-0">
              <svg className="h-5 w-5 text-[#C9A24D]" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
              </svg>
            </div>
            <div className="ml-3">
              <p className="text-sm font-medium text-[#2D3A3A]">
                <span className="font-semibold">Warning:</span> Posting is irreversible. This will create accounting entries that cannot be modified. Only reversal is allowed.
              </p>
            </div>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-6">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Posting Date <span className="text-red-500">*</span>
            </label>
            <input
              type="date"
              required
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#1F6F5C] focus:border-[#1F6F5C] transition-colors"
            />
            <p className="text-xs text-gray-500 mt-2">
              The posting date must be within the project's crop cycle date range.
            </p>
          </div>

          {/* Ledger Preview Table */}
          <div className="border border-gray-200 rounded-lg overflow-hidden">
            <div className="bg-[#E6ECEA] px-4 py-3 border-b border-gray-200">
              <h3 className="text-sm font-semibold text-gray-900">Ledger Preview</h3>
            </div>
            {previewLoading ? (
              <div className="p-8 text-center text-sm text-gray-500">
                Loading preview...
              </div>
            ) : previewLines.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">
                        Account
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">
                        Description
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">
                        Debit
                      </th>
                      <th className="px-4 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">
                        Credit
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {previewLines.map((line, index) => (
                      <tr key={index} className="hover:bg-gray-50">
                        <td className="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                          {line.account}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-600">
                          {line.description || '—'}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-900 text-right whitespace-nowrap">
                          {line.debit > 0 ? (
                            <span className="tabular-nums">{formatMoney(line.debit, { currencyCode: entry?.currency_code || 'GBP' })}</span>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-sm text-gray-900 text-right whitespace-nowrap">
                          {line.credit > 0 ? (
                            <span className="tabular-nums">{formatMoney(line.credit, { currencyCode: entry?.currency_code || 'GBP' })}</span>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>
                      </tr>
                    ))}
                    {/* Totals Row */}
                    <tr className="bg-gray-50 font-semibold border-t-2 border-gray-300">
                      <td className="px-4 py-3 text-sm text-gray-900" colSpan={2}>
                        Totals
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-right whitespace-nowrap">
                        <span className="tabular-nums">{formatMoney(totalDebit, { currencyCode: entry?.currency_code || 'GBP' })}</span>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-900 text-right whitespace-nowrap">
                        <span className="tabular-nums">{formatMoney(totalCredit, { currencyCode: entry?.currency_code || 'GBP' })}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="p-8 text-center text-sm text-gray-500">
                No preview available
              </div>
            )}
          </div>

          <div className="flex justify-end space-x-3 pt-4 border-t border-gray-200">
            <button
              type="button"
              onClick={onClose}
              disabled={loading}
              className="px-5 py-2.5 text-sm font-medium border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-5 py-2.5 text-sm font-medium bg-[#1F6F5C] text-white rounded-lg hover:bg-[#1a5a4a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              {loading ? 'Posting...' : 'Post Entry'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
