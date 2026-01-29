import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import {
  useChargeQuery,
  useUpdateCharge,
  usePostCharge,
  useReverseCharge,
} from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
export default function ChargeDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { formatMoney, formatDate } = useFormatting();
  const { hasRole } = useRole();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');
  const [editedLines, setEditedLines] = useState<Record<string, { rate: string; amount: string }>>({});

  const { data: charge, isLoading } = useChargeQuery(id!);
  const updateMutation = useUpdateCharge();
  const postMutation = usePostCharge();
  const reverseMutation = useReverseCharge();

  const canEdit = hasRole(['tenant_admin', 'accountant']);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const isDraft = charge?.status === 'DRAFT';
  const isPosted = charge?.status === 'POSTED';

  // Initialize edited lines from charge lines
  useEffect(() => {
    if (charge?.lines && isDraft) {
      const initial: Record<string, { rate: string; amount: string }> = {};
      charge.lines.forEach((line) => {
        initial[line.id] = {
          rate: line.rate != null ? String(line.rate) : '',
          amount: line.amount != null ? String(line.amount) : '',
        };
      });
      setEditedLines(initial);
    }
  }, [charge?.lines, isDraft]);

  const handleLineChange = (lineId: string, field: 'rate' | 'amount', value: string) => {
    if (!isDraft) return;
    setEditedLines((prev) => {
      const updated = { ...prev };
      if (!updated[lineId]) {
        updated[lineId] = { rate: '', amount: '' };
      }
      updated[lineId][field] = value;
      
      // If rate changed, recalculate amount
      if (field === 'rate') {
        const line = charge?.lines?.find((l) => l.id === lineId);
        if (line) {
          const rateNum = parseFloat(value) || 0;
          updated[lineId].amount = String(rateNum * parseFloat(line.usage_qty));
        }
      }
      return updated;
    });
  };

  const handleSave = async () => {
    if (!id || !isDraft) return;
    
    const lines = Object.entries(editedLines).map(([lineId, values]) => ({
      id: lineId,
      rate: parseFloat(values.rate) || 0,
      amount: parseFloat(values.amount) || 0,
    }));

    try {
      await updateMutation.mutateAsync({
        id,
        payload: { lines },
      });
    } catch (error) {
      // Error handled by mutation
    }
  };

  const handlePost = async () => {
    if (!id) return;
    try {
      await postMutation.mutateAsync({
        id,
        payload: {
          posting_date: postingDate,
        },
      });
      setShowPostModal(false);
    } catch (error) {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!id) return;
    try {
      await reverseMutation.mutateAsync({
        id,
        payload: {
          posting_date: reverseDate,
          reason: reverseReason || undefined,
        },
      });
      setShowReverseModal(false);
      setReverseReason('');
    } catch (error) {
      // Error handled by mutation
    }
  };

  // Calculate total from edited lines or charge
  const totalAmount = isDraft && Object.keys(editedLines).length > 0
    ? Object.values(editedLines).reduce((sum, line) => sum + (parseFloat(line.amount) || 0), 0)
    : parseFloat(charge?.total_amount || '0');

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!charge) {
    return <div>Charge not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link
          to="/app/machinery/charges"
          className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block"
        >
          ← Back to Charges
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Charge Details</h1>
      </div>

      {/* Header Info */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Charge No</dt>
            <dd className="text-sm text-gray-900 font-semibold">{charge.charge_no}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span
                className={`px-2 py-1 rounded text-xs ${
                  charge.status === 'DRAFT'
                    ? 'bg-yellow-100 text-yellow-800'
                    : charge.status === 'POSTED'
                    ? 'bg-green-100 text-green-800'
                    : 'bg-red-100 text-red-800'
                }`}
              >
                {charge.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Project</dt>
            <dd className="text-sm text-gray-900">{charge.project?.name || 'N/A'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
            <dd className="text-sm text-gray-900">{charge.crop_cycle?.name || 'N/A'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Pool Scope</dt>
            <dd className="text-sm text-gray-900">{charge.pool_scope}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Charge Date</dt>
            <dd className="text-sm text-gray-900">{formatDate(charge.charge_date)}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Landlord Party</dt>
            <dd className="text-sm text-gray-900">{charge.landlord_party?.name || 'N/A'}</dd>
          </div>
          {charge.posting_date && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
              <dd className="text-sm text-gray-900">{formatDate(charge.posting_date)}</dd>
            </div>
          )}
          {charge.posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${charge.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
          {charge.reversal_posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Reversal Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${charge.reversal_posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Lines Table */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Charge Lines</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Machine
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Work Log
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Usage Qty
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Rate
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Amount
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {charge.lines?.map((line) => {
                const edited = editedLines[line.id];
                const rate = edited ? edited.rate : (line.rate != null ? String(line.rate) : '');
                const amount = edited ? edited.amount : (line.amount != null ? String(line.amount) : '');
                return (
                  <tr key={line.id}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {line.work_log?.machine?.code || 'N/A'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {line.work_log?.work_log_no || 'N/A'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right tabular-nums">
                      {line.usage_qty} {line.unit}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                      {isDraft ? (
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          value={rate}
                          onChange={(e) =>
                            handleLineChange(line.id, 'rate', e.target.value)
                          }
                          className="w-24 px-2 py-1 border border-gray-300 rounded text-right tabular-nums"
                        />
                      ) : (
                        <span className="tabular-nums">{formatMoney(rate)}</span>
                      )}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                      {isDraft ? (
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          value={amount}
                          onChange={(e) =>
                            handleLineChange(line.id, 'amount', e.target.value)
                          }
                          className="w-24 px-2 py-1 border border-gray-300 rounded text-right tabular-nums"
                        />
                      ) : (
                        <span className="tabular-nums">{formatMoney(amount)}</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot className="bg-gray-50">
              <tr>
                <td colSpan={4} className="px-6 py-4 text-right text-sm font-medium text-gray-900">
                  Total:
                </td>
                <td className="px-6 py-4 text-right text-sm font-medium text-gray-900 tabular-nums">
                  {formatMoney(totalAmount)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      {/* Actions */}
      {canEdit && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Actions</h2>
          <div className="flex gap-2">
            {isDraft && (
              <>
                <button
                  onClick={handleSave}
                  disabled={updateMutation.isPending}
                  className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
                >
                  {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
                </button>
                {canPost && (
                  <button
                    onClick={() => setShowPostModal(true)}
                    className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                  >
                    Post Charge
                  </button>
                )}
              </>
            )}
            {isPosted && canPost && (
              <button
                onClick={() => setShowReverseModal(true)}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
              >
                Reverse Charge
              </button>
            )}
          </div>
        </div>
      )}

      {/* Post Modal */}
      {showPostModal && (
        <Modal isOpen={showPostModal} title="Post Charge" onClose={() => setShowPostModal(false)}>
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                required
              />
            </FormField>
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowPostModal(false)}
                className="px-4 py-2 border rounded"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                disabled={postMutation.isPending}
              >
                {postMutation.isPending ? 'Posting...' : 'Post'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* Reverse Modal */}
      {showReverseModal && (
        <Modal isOpen={showReverseModal} title="Reverse Charge" onClose={() => setShowReverseModal(false)}>
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={reverseDate}
                onChange={(e) => setReverseDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                required
              />
            </FormField>
            <FormField label="Reason">
              <textarea
                value={reverseReason}
                onChange={(e) => setReverseReason(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                rows={3}
                maxLength={500}
                placeholder="Optional reason for reversal"
              />
            </FormField>
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowReverseModal(false)}
                className="px-4 py-2 border rounded"
                disabled={reverseMutation.isPending}
              >
                Cancel
              </button>
              <button
                onClick={handleReverse}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
                disabled={reverseMutation.isPending}
              >
                {reverseMutation.isPending ? 'Reversing...' : 'Reverse'}
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
