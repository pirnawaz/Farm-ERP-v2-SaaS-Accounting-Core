import { useEffect, useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { PostingGroup, apiClient } from '@farm-erp/shared'
import { useFormatting } from '../hooks/useFormatting'

function PostingGroupDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { formatMoney, formatDateTime } = useFormatting()
  const [postingGroup, setPostingGroup] = useState<PostingGroup | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showReverseModal, setShowReverseModal] = useState(false)
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0])
  const [reverseReason, setReverseReason] = useState('')
  const [reversing, setReversing] = useState(false)

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true)
        setError(null)
        const data = await apiClient.get<PostingGroup>(`/api/posting-groups/${id}`)
        setPostingGroup(data)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch posting group')
      } finally {
        setLoading(false)
      }
    }

    if (id) {
      fetchData()
    }
  }, [id])

  const handleReverse = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!postingGroup || !reverseReason.trim()) return

    setReversing(true)
    try {
      const reversal = await apiClient.post<PostingGroup>(
        `/api/posting-groups/${postingGroup.id}/reverse`,
        {
          posting_date: reversePostingDate,
          reason: reverseReason,
        }
      )
      setShowReverseModal(false)
      setReverseReason('')
      navigate(`/posting-groups/${reversal.id}`)
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to reverse posting group')
    } finally {
      setReversing(false)
    }
  }

  if (loading) {
    return <div className="text-gray-600">Loading posting group...</div>
  }

  if (error || !postingGroup) {
    return (
      <div className="bg-red-50 border border-red-200 rounded p-4">
        <p className="text-red-800">{error || 'Posting group not found'}</p>
        <Link to="/daily-book-entries" className="text-blue-600 hover:underline mt-2 inline-block">
          Back to Entries
        </Link>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h2 className="text-2xl font-bold">Posting Group Details</h2>
        <Link
          to="/daily-book-entries"
          className="text-blue-600 hover:text-blue-800"
        >
          ← Back to Entries
        </Link>
      </div>

      {/* Posting Group Info */}
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex justify-between items-start mb-4">
          <h3 className="text-lg font-semibold">Posting Group Information</h3>
          {postingGroup.source_type !== 'REVERSAL' && (
            <button
              onClick={() => setShowReverseModal(true)}
              className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
            >
              Reverse
            </button>
          )}
        </div>
        <dl className="grid grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
            <dd className="mt-1 text-sm text-gray-900">{postingGroup.posting_date}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Source Type</dt>
            <dd className="mt-1 text-sm text-gray-900">{postingGroup.source_type}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Project</dt>
            <dd className="mt-1 text-sm text-gray-900">
              {postingGroup.project?.name || 'N/A'}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Created At</dt>
            <dd className="mt-1 text-sm text-gray-900">
              {formatDateTime(postingGroup.created_at)}
            </dd>
          </div>
          {postingGroup.reversal_of_posting_group_id && (
            <>
              <div>
                <dt className="text-sm font-medium text-gray-500">Reversal Of</dt>
                <dd className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/posting-groups/${postingGroup.reversal_of_posting_group_id}`}
                    className="text-blue-600 hover:underline"
                  >
                    {postingGroup.reversal_of_posting_group_id}
                  </Link>
                </dd>
              </div>
              {postingGroup.correction_reason && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Correction Reason</dt>
                  <dd className="mt-1 text-sm text-gray-900">{postingGroup.correction_reason}</dd>
                </div>
              )}
            </>
          )}
        </dl>
      </div>

      {/* Allocation Rows */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold">Allocation Rows</h3>
        </div>
        {postingGroup.allocation_rows && postingGroup.allocation_rows.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Cost Type
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Amount
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Currency
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Rule Version
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Rule Hash
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {postingGroup.allocation_rows.map((row) => (
                  <tr key={row.id}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {row.cost_type}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      {formatMoney(row.amount)}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {row.currency_code}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {row.rule_version || 'N/A'}
                    </td>
                    <td className="px-6 py-4 text-sm text-gray-500">
                      {row.rule_hash ? (
                        <details className="cursor-pointer">
                          <summary className="text-blue-600 hover:underline">
                            {row.rule_hash.substring(0, 8)}...
                          </summary>
                          <pre className="mt-2 p-2 bg-gray-100 rounded text-xs overflow-auto max-w-md">
                            {JSON.stringify(row.rule_snapshot_json, null, 2)}
                          </pre>
                        </details>
                      ) : (
                        'N/A'
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="px-6 py-4 text-sm text-gray-500">No allocation rows</div>
        )}
      </div>

      {/* Ledger Entries */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold">Ledger Entries</h3>
        </div>
        {postingGroup.ledger_entries && postingGroup.ledger_entries.length > 0 ? (
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Account
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  Debit
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                  Credit
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Currency
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {postingGroup.ledger_entries.map((entry) => (
                <tr key={entry.id}>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {entry.account?.name || entry.account?.code || 'N/A'}
                    <span className="text-gray-500 ml-2">({entry.account?.code || 'N/A'})</span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                    {parseFloat(entry.debit.toString()) > 0 ? formatMoney(entry.debit) : '-'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                    {parseFloat(entry.credit.toString()) > 0 ? formatMoney(entry.credit) : '-'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    {entry.currency_code}
                  </td>
                </tr>
              ))}
              <tr className="bg-gray-50 font-semibold">
                <td className="px-6 py-4 whitespace-nowrap text-sm">Total</td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                  {formatMoney(
                    postingGroup.ledger_entries
                      .reduce((sum, e) => sum + parseFloat(e.debit.toString()), 0)
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-right">
                  {formatMoney(
                    postingGroup.ledger_entries
                      .reduce((sum, e) => sum + parseFloat(e.credit.toString()), 0)
                  )}
                </td>
                <td></td>
              </tr>
            </tbody>
          </table>
        ) : (
          <div className="px-6 py-4 text-sm text-gray-500">No ledger entries</div>
        )}
      </div>

      {/* Reverse Modal */}
      {showReverseModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 max-w-md w-full">
            <h3 className="text-lg font-semibold mb-4">Reverse Posting Group</h3>
            <form onSubmit={handleReverse}>
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Posting Date
                </label>
                <input
                  type="date"
                  value={reversePostingDate}
                  onChange={(e) => setReversePostingDate(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  required
                />
              </div>
              <div className="mb-4">
                <label className="block text-sm font-medium text-gray-700 mb-1">
                  Reason
                </label>
                <textarea
                  value={reverseReason}
                  onChange={(e) => setReverseReason(e.target.value)}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md"
                  rows={4}
                  required
                  placeholder="Enter reason for reversal..."
                />
              </div>
              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => {
                    setShowReverseModal(false)
                    setReverseReason('')
                  }}
                  className="px-4 py-2 border border-gray-300 rounded hover:bg-gray-50"
                  disabled={reversing}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 disabled:opacity-50"
                  disabled={reversing}
                >
                  {reversing ? 'Reversing...' : 'Reverse'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}

export default PostingGroupDetailPage
