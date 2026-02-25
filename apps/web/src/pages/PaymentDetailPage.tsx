import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { usePayment, useDeletePayment, usePostPayment, useReversePayment } from '../hooks/usePayments';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { PageHeader } from '../components/PageHeader';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useCropCycles } from '../hooks/useCropCycles';
import { useFormatting } from '../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';
import { Term } from '../components/Term';
import { term } from '../config/terminology';

export default function PaymentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: payment, isLoading } = usePayment(id || '');
  const deleteMutation = useDeletePayment();
  const postMutation = usePostPayment();
  const reverseMutation = useReversePayment();
  const { data: cropCycles } = useCropCycles();
  const { hasRole } = useRole();
  const { formatMoney, formatDateTime } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversePostingDate, setReversePostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversalReason, setReversalReason] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [idempotencyKey] = useState(uuidv4());

  const isDraft = payment?.status === 'DRAFT';
  const isPosted = payment?.status === 'POSTED';
  const isReversed = Boolean(payment?.reversal_posting_group_id);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canReverse = canPost && isPosted && !isReversed;

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(id);
      navigate('/app/payments');
    } catch (error: any) {
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
          idempotency_key: idempotencyKey,
          crop_cycle_id: payment?.settlement_id ? undefined : cropCycleId || undefined,
          // For Payment IN, default to FIFO allocation
          allocation_mode: payment?.direction === 'IN' ? 'FIFO' : undefined,
        },
      });
      setShowPostModal(false);
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!id) return;
    try {
      await reverseMutation.mutateAsync({
        id,
        payload: {
          posting_date: reversePostingDate,
          reason: reversalReason || undefined,
        },
      });
      setShowReverseModal(false);
      setReversalReason('');
    } catch (error: any) {
      // Error handled by mutation
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!payment) {
    return <div>Payment not found</div>;
  }

  return (
    <div>
      <PageHeader
        title={payment.reference ? `Payment ${payment.reference}` : 'Payment'}
        backTo="/app/payments"
        breadcrumbs={[
          { label: 'Sales & Money', to: '/app/sales' },
          { label: 'Payments', to: '/app/payments' },
          { label: payment.reference || 'Payment' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Direction</dt>
            <dd className="text-sm text-gray-900">{payment.direction}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span className={`px-2 py-1 rounded text-xs ${
                payment.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
              }`}>
                {payment.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Party</dt>
            <dd className="text-sm text-gray-900">{payment.party?.name || payment.party_id}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900"><span className="tabular-nums">{formatMoney(payment.amount)}</span></dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Payment Date</dt>
            <dd className="text-sm text-gray-900">{payment.payment_date}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Method</dt>
            <dd className="text-sm text-gray-900">{payment.method}</dd>
          </div>
          {payment.reference && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Reference</dt>
              <dd className="text-sm text-gray-900">{payment.reference}</dd>
            </div>
          )}
          {payment.settlement_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Settlement</dt>
              <dd className="text-sm text-gray-900">
                <Link to={`/app/settlement`} className="text-[#1F6F5C] hover:text-[#1a5a4a]">
                  {payment.settlement_id}
                </Link>
              </dd>
            </div>
          )}
          {payment.posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500"><Term k="postingGroup" showHint /></dt>
              <dd className="text-sm text-gray-900">
                <Link to={`/app/posting-groups/${payment.posting_group_id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a]">
                  {payment.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {payment.posted_at && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posted At</dt>
              <dd className="text-sm text-gray-900">{formatDateTime(payment.posted_at)}</dd>
            </div>
          )}
          {payment.reversal_posting_group_id && (
            <>
              <div>
                <dt className="text-sm font-medium text-gray-500"><Term k="reversalPostingGroup" showHint /></dt>
                <dd className="text-sm text-gray-900">
                  <Link to={`/app/posting-groups/${payment.reversal_posting_group_id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a]">
                    {payment.reversal_posting_group_id}
                  </Link>
                </dd>
              </div>
              {payment.reversed_at && (
                <div>
                  <dt className="text-sm font-medium text-gray-500">Reversed At</dt>
                  <dd className="text-sm text-gray-900">{formatDateTime(payment.reversed_at)}</dd>
                </div>
              )}
              {payment.reversal_reason && (
                <div className="md:col-span-2">
                  <dt className="text-sm font-medium text-gray-500">Reversal Reason</dt>
                  <dd className="text-sm text-gray-900">{payment.reversal_reason}</dd>
                </div>
              )}
            </>
          )}
          {payment.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Notes</dt>
              <dd className="text-sm text-gray-900">{payment.notes}</dd>
            </div>
          )}
          {payment.direction === 'IN' && payment.sale_allocations && payment.sale_allocations.length > 0 && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500 mb-2">Applied to Sales</dt>
              <dd className="text-sm text-gray-900">
                <div className="space-y-2">
                  {payment.sale_allocations.map((alloc) => (
                    <div key={alloc.id} className="flex justify-between items-center bg-gray-50 p-2 rounded">
                      <div>
                        <Link
                          to={`/app/sales/${alloc.sale_id}`}
                          className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                        >
                          {alloc.sale?.sale_no || (alloc.sale_id ? `Sale ${alloc.sale_id.substring(0, 8)}` : 'N/A')}
                        </Link>
                        <span className="text-xs text-gray-500 ml-2">
                          ({alloc.allocation_date})
                        </span>
                      </div>
                      <span className="font-medium"><span className="tabular-nums">{formatMoney(alloc.amount)}</span></span>
                    </div>
                  ))}
                </div>
              </dd>
            </div>
          )}
        </dl>
      </div>

      {(isDraft || canReverse) && (
        <div className="bg-white rounded-lg shadow p-6 flex justify-end space-x-4">
          {isDraft && (
            <>
              <Link
                to={`/app/payments/${id}/edit`}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Edit
              </Link>
              <button
                onClick={() => setShowDeleteConfirm(true)}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              >
                Delete
              </button>
              {canPost && (
                <button
                  onClick={() => setShowPostModal(true)}
                  className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
                >
                  {term('postAction')}
                </button>
              )}
            </>
          )}
          {canReverse && (
            <button
              onClick={() => setShowReverseModal(true)}
              className="px-4 py-2 border border-amber-600 text-amber-700 rounded-md hover:bg-amber-50"
            >
              {term('reverseAction')}
            </button>
          )}
        </div>
      )}

      <Modal isOpen={showPostModal} onClose={() => setShowPostModal(false)} title={term('postAction')}>
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          {!payment.settlement_id && (
            <FormField label="Crop Cycle" required>
              <select
                value={cropCycleId}
                onChange={(e) => setCropCycleId(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              >
                <option value="">Select crop cycle</option>
                {cropCycles?.map((cycle) => (
                  <option key={cycle.id} value={cycle.id}>
                    {cycle.name}
                  </option>
                ))}
              </select>
            </FormField>
          )}
          <FormField label="Idempotency Key">
            <input
              type="text"
              value={idempotencyKey}
              disabled
              className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100"
            />
          </FormField>
          <div className="flex justify-end space-x-4 pt-4">
            <button
              onClick={() => setShowPostModal(false)}
              className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handlePost}
              disabled={postMutation.isPending || (!payment.settlement_id && !cropCycleId)}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] disabled:opacity-50"
            >
              {postMutation.isPending ? term('postActionPending') : term('postAction')}
            </button>
          </div>
        </div>
      </Modal>

      <Modal isOpen={showReverseModal} onClose={() => setShowReverseModal(false)} title={term('reverseAction')}>
        <div className="space-y-4">
          <p className="text-sm text-gray-600">
            This will create a reversal transaction that negates the original accounting lines. The original payment and posted transaction will not be changed.
          </p>
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={reversePostingDate}
              onChange={(e) => setReversePostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Reason (optional)">
            <textarea
              value={reversalReason}
              onChange={(e) => setReversalReason(e.target.value)}
              rows={3}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              placeholder="e.g. Duplicate entry, wrong amount"
            />
          </FormField>
          <div className="flex justify-end space-x-4 pt-4">
            <button
              onClick={() => setShowReverseModal(false)}
              className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              onClick={handleReverse}
              disabled={reverseMutation.isPending}
              className="px-4 py-2 bg-amber-600 text-white rounded-md hover:bg-amber-700 disabled:opacity-50"
            >
              {reverseMutation.isPending ? term('reverseActionPending') : term('reverseAction')}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleDelete}
        title="Delete Payment"
        message="Are you sure you want to delete this payment? This action cannot be undone."
      />
    </div>
  );
}
