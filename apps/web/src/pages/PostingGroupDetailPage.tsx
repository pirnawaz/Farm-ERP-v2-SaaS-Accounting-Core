import { useEffect, useState } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { PostingGroup, type LedgerEntry, apiClient } from '@farm-erp/shared'
import { useFormatting } from '../hooks/useFormatting'
import { term } from '../config/terminology'
import { Term } from '../components/Term'
import { PageHeader } from '../components/PageHeader'
import { Modal } from '../components/Modal'
import { FormField } from '../components/FormField'
import { LoadingSpinner } from '../components/LoadingSpinner'
import { PageContainer } from '../components/PageContainer'

const GL_LIST_PATH = '/app/reports/general-ledger'

function ledgerDr(e: LedgerEntry): number {
  const v = e.debit ?? e.debit_amount
  return v !== undefined && v !== null ? parseFloat(String(v)) : 0
}

function ledgerCr(e: LedgerEntry): number {
  const v = e.credit ?? e.credit_amount
  return v !== undefined && v !== null ? parseFloat(String(v)) : 0
}

function PostingGroupDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { formatMoney, formatDate, formatDateTime } = useFormatting()
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
        setError(err instanceof Error ? err.message : 'Failed to fetch')
      } finally {
        setLoading(false)
      }
    }

    if (id) {
      fetchData()
    }
  }, [id])

  const closeReverseModal = () => {
    setShowReverseModal(false)
    setReverseReason('')
  }

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
      closeReverseModal()
      navigate(`/app/posting-groups/${reversal.id}`)
    } catch (err) {
      alert(err instanceof Error ? err.message : 'Failed to reverse posting group')
    } finally {
      setReversing(false)
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (error || !postingGroup) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={term('postingGroup')}
          backTo={GL_LIST_PATH}
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Profit & Reports', to: '/app/reports' },
            { label: 'General ledger', to: GL_LIST_PATH },
            { label: 'Not found' },
          ]}
        />
        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
          <p className="text-red-800">{error || 'Not found'}</p>
          <Link to={GL_LIST_PATH} className="text-[#1F6F5C] hover:underline mt-2 inline-block text-sm font-medium">
            Back to General ledger
          </Link>
        </div>
      </div>
    )
  }

  const titleLabel = `${term('postingGroup')} · ${postingGroup.id.length > 12 ? `${postingGroup.id.slice(0, 8)}…` : postingGroup.id}`

  const showLedgerBaseCols =
    postingGroup.ledger_entries?.some(
      (e) => e.debit_amount_base != null || e.credit_amount_base != null
    ) ?? false

  return (
    <PageContainer className="space-y-6">
      <PageHeader
        title={titleLabel}
        backTo={GL_LIST_PATH}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Profit & Reports', to: '/app/reports' },
          { label: 'General ledger', to: GL_LIST_PATH },
          { label: postingGroup.id.length > 12 ? `${postingGroup.id.slice(0, 8)}…` : postingGroup.id },
        ]}
      />

      {/* Posting Group Info */}
      <div className="bg-white rounded-lg shadow p-6" data-testid="posting-group-panel">
        <div className="flex flex-wrap justify-between items-start gap-4 mb-4">
          <h3 className="text-lg font-semibold"><Term k="postingGroup" showHint /> Information</h3>
          {postingGroup.source_type !== 'REVERSAL' && (
            <button
              type="button"
              data-testid="create-correction-btn"
              onClick={() => setShowReverseModal(true)}
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 shrink-0"
            >
              {term('reverseAction')}
            </button>
          )}
        </div>
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
            <dd className="mt-1 text-sm text-gray-900">{formatDate(postingGroup.posting_date)}</dd>
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
          {postingGroup.currency_code && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Transaction currency</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">{postingGroup.currency_code}</dd>
            </div>
          )}
          {postingGroup.base_currency_code && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Functional (base) currency</dt>
              <dd className="mt-1 text-sm font-mono text-gray-900">{postingGroup.base_currency_code}</dd>
            </div>
          )}
          {postingGroup.fx_rate != null &&
            postingGroup.fx_rate !== '' &&
            String(postingGroup.fx_rate) !== '1' &&
            String(postingGroup.fx_rate) !== '1.00000000' && (
              <div>
                <dt className="text-sm font-medium text-gray-500">FX rate (base per 1 transaction unit)</dt>
                <dd className="mt-1 text-sm text-gray-900 tabular-nums">{String(postingGroup.fx_rate)}</dd>
              </div>
            )}
          {postingGroup.reversal_of_posting_group_id && (
            <>
              <div>
                <dt className="text-sm font-medium text-gray-500">Reversal Of</dt>
                <dd className="mt-1 text-sm text-gray-900">
                  <Link
                    to={`/app/posting-groups/${postingGroup.reversal_of_posting_group_id}`}
                    className="text-[#1F6F5C] hover:underline"
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
      <div className="bg-white rounded-lg shadow overflow-hidden" data-testid="allocation-rows-table">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold"><Term k="allocationRows" showHint /></h3>
        </div>
        {postingGroup.allocation_rows && postingGroup.allocation_rows.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Cost Type
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Amount
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Currency
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Rule Version
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Rule Hash
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {postingGroup.allocation_rows.map((row) => (
                  <tr key={row.id}>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-normal break-words text-sm text-gray-900">
                      {row.cost_type}
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                      <span className="tabular-nums">{formatMoney(row.amount)}</span>
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-500">
                      {row.currency_code}
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-500">
                      {row.rule_version || 'N/A'}
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 text-sm text-gray-500">
                      {row.rule_hash ? (
                        <details className="cursor-pointer">
                          <summary className="text-[#1F6F5C] hover:underline">
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
          <div className="px-6 py-4 text-sm text-gray-500">No {term('allocationRows').toLowerCase()}</div>
        )}
      </div>

      {/* Ledger Entries */}
      <div className="bg-white rounded-lg shadow overflow-hidden" data-testid="ledger-entries-table">
        <div className="px-6 py-4 border-b border-gray-200">
          <h3 className="text-lg font-semibold"><Term k="ledgerEntries" showHint /></h3>
        </div>
        {postingGroup.ledger_entries && postingGroup.ledger_entries.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-[#E6ECEA]">
                <tr>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Account
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Debit
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                    Credit
                  </th>
                  <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                    Currency
                  </th>
                  {showLedgerBaseCols && (
                    <>
                      <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                        Debit ({postingGroup.base_currency_code ?? 'base'})
                      </th>
                      <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">
                        Credit ({postingGroup.base_currency_code ?? 'base'})
                      </th>
                    </>
                  )}
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {postingGroup.ledger_entries.map((entry) => (
                  <tr key={entry.id}>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-normal break-words text-sm text-gray-900">
                      {entry.account?.name || entry.account?.code || 'N/A'}
                      <span className="text-gray-500 ml-2">({entry.account?.code || 'N/A'})</span>
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                      {ledgerDr(entry) > 0 ? <span className="tabular-nums">{formatMoney(ledgerDr(entry))}</span> : '-'}
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                      {ledgerCr(entry) > 0 ? <span className="tabular-nums">{formatMoney(ledgerCr(entry))}</span> : '-'}
                    </td>
                    <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-gray-500">
                      {entry.currency_code}
                    </td>
                    {showLedgerBaseCols && (
                      <>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right text-gray-800">
                          {entry.debit_amount_base != null && parseFloat(String(entry.debit_amount_base)) > 0
                            ? <span className="tabular-nums">{formatMoney(entry.debit_amount_base)}</span>
                            : '—'}
                        </td>
                        <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right text-gray-800">
                          {entry.credit_amount_base != null && parseFloat(String(entry.credit_amount_base)) > 0
                            ? <span className="tabular-nums">{formatMoney(entry.credit_amount_base)}</span>
                            : '—'}
                        </td>
                      </>
                    )}
                  </tr>
                ))}
                <tr className="bg-gray-50 font-semibold">
                  <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm">Total</td>
                  <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right">
                    <span className="tabular-nums">{formatMoney(
                      postingGroup.ledger_entries
                        .reduce((sum, e) => sum + ledgerDr(e), 0)
                    )}</span>
                  </td>
                  <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right">
                    <span className="tabular-nums">{formatMoney(
                      postingGroup.ledger_entries
                        .reduce((sum, e) => sum + ledgerCr(e), 0)
                    )}</span>
                  </td>
                  <td></td>
                  {showLedgerBaseCols && (
                    <>
                      <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right">
                        <span className="tabular-nums">{formatMoney(
                          postingGroup.ledger_entries.reduce(
                            (sum, e) => sum + (e.debit_amount_base != null ? parseFloat(String(e.debit_amount_base)) : 0),
                            0
                          )
                        )}</span>
                      </td>
                      <td className="px-3 sm:px-6 py-3 sm:py-4 whitespace-nowrap text-sm text-right">
                        <span className="tabular-nums">{formatMoney(
                          postingGroup.ledger_entries.reduce(
                            (sum, e) => sum + (e.credit_amount_base != null ? parseFloat(String(e.credit_amount_base)) : 0),
                            0
                          )
                        )}</span>
                      </td>
                    </>
                  )}
                </tr>
              </tbody>
            </table>
          </div>
        ) : (
          <div className="px-6 py-4 text-sm text-gray-500">No {term('ledgerEntries').toLowerCase()}</div>
        )}
      </div>

      <Modal
        isOpen={showReverseModal}
        onClose={closeReverseModal}
        title={term('reversalPostingGroup')}
        size="md"
        testId="posting-group-reverse-modal"
      >
        <form onSubmit={handleReverse} className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={reversePostingDate}
              onChange={(e) => setReversePostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
              required
            />
          </FormField>
          <FormField label="Reason" required>
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
              rows={4}
              required
              placeholder="Enter reason for reversal..."
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <button
              type="button"
              onClick={closeReverseModal}
              className="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              disabled={reversing}
            >
              Cancel
            </button>
            <button
              type="submit"
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
              disabled={reversing}
            >
              {reversing ? term('reverseActionPending') : term('reverseAction')}
            </button>
          </div>
        </form>
      </Modal>
    </PageContainer>
  )
}

export default PostingGroupDetailPage
