import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useOperationalTransaction, useDeleteOperationalTransaction, usePostOperationalTransaction, useOperationalTransactions } from '../hooks/useOperationalTransactions';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';
import { v4 as uuidv4 } from 'uuid';

export default function TransactionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: transaction, isLoading } = useOperationalTransaction(id || '');
  const { data: postedTransactions } = useOperationalTransactions({ status: 'POSTED' });
  const deleteMutation = useDeleteOperationalTransaction();
  const postMutation = usePostOperationalTransaction();
  const { canPost } = useRole();
  const { formatDate } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());

  const isDraft = transaction?.status === 'DRAFT';

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(id);
      toast.success('Transaction deleted successfully');
      // Navigate back to list
      window.location.href = '/app/transactions';
    } catch (error: any) {
      toast.error(error.message || 'Failed to delete transaction');
    }
  };

  const handlePost = async () => {
    if (!id) return;
    try {
      // Check if this is the first posted transaction
      const isFirstPosted = !postedTransactions || postedTransactions.length === 0;
      
      const result = await postMutation.mutateAsync({
        id,
        payload: {
          posting_date: postingDate,
          idempotency_key: idempotencyKey,
        },
      });
      
      if (isFirstPosted) {
        toast.success('Your first transaction has been posted. You can now view reports.');
      } else {
        toast.success(`Transaction posted successfully. Posting Group: ${result.id}`);
      }
      setShowPostModal(false);
    } catch (error: any) {
      toast.error(error.message || 'Failed to post transaction');
    }
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!transaction) {
    return <div data-testid="transaction-detail">Transaction not found</div>;
  }

  return (
    <div data-testid="transaction-detail">
      <div className="mb-6">
        <Link to="/app/transactions" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Transactions
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Transaction Details</h1>
        {id && (
          <p className="text-sm text-gray-500 mt-1" data-testid="transaction-id">
            {id}
          </p>
        )}
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Type</dt>
            <dd className="text-sm text-gray-900">{transaction.type}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span
                data-testid="status-badge"
                className={`px-2 py-1 rounded text-xs ${
                  transaction.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
                }`}
              >
                {transaction.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Transaction Date</dt>
            <dd className="text-sm text-gray-900">{formatDate(transaction.transaction_date)}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900">{transaction.amount}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Classification</dt>
            <dd className="text-sm text-gray-900">{transaction.classification}</dd>
          </div>
          {transaction.project && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Project</dt>
              <dd className="text-sm text-gray-900">
                <Link to={`/app/projects/${transaction.project.id}`} className="text-[#1F6F5C] hover:text-[#1a5a4a]">
                  {transaction.project.name}
                </Link>
              </dd>
            </div>
          )}
          {transaction.crop_cycle && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{transaction.crop_cycle.name}</dd>
            </div>
          )}
        </dl>
      </div>

      {transaction.posting_scope_mismatch && (
        <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-amber-800">
            {transaction.posting_scope_mismatch_reason ?? `Classified as ${transaction.classification} but posted as SHARED (legacy). Settlement will treat it as shared unless corrected.`}
          </p>
        </div>
      )}
      {transaction.posting_group_id && (
        <div className="bg-white rounded-lg shadow p-6 mb-6" data-testid="posting-group-panel">
          <p className="text-sm text-gray-700">
            Posting Group{' '}
            <Link to={`/app/posting-groups/${transaction.posting_group_id}`} className="font-medium text-[#1F6F5C] hover:underline" data-testid="posting-group-id">
              {transaction.posting_group_id.substring(0, 8)}...
            </Link>
          </p>
        </div>
      )}
      {transaction.correction_posting_group_id && (
        <div className="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
          <p className="text-sm text-green-800">
            Corrected by Posting Group{' '}
            <Link to={`/app/posting-groups/${transaction.correction_posting_group_id}`} className="font-medium text-[#1F6F5C] hover:underline">
              {transaction.correction_posting_group_id.substring(0, 8)}...
            </Link>
          </p>
        </div>
      )}

      {isDraft && (
        <div className="flex space-x-3">
          <Link
            to={`/app/transactions/${id}/edit`}
            className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
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
              type="button"
              data-testid="post-btn"
              onClick={() => setShowPostModal(true)}
              className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
            >
              Post Transaction
            </button>
          )}
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => setShowPostModal(false)}
        title="Post Transaction"
        testId="posting-date-modal"
      >
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              data-testid="posting-date-input"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>
          <FormField label="Idempotency Key">
            <input
              type="text"
              value={idempotencyKey}
              readOnly
              className="w-full px-3 py-2 border border-gray-300 rounded-md bg-gray-100"
            />
          </FormField>
          <div className="flex justify-end space-x-3">
            <button
              type="button"
              onClick={() => setShowPostModal(false)}
              className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="button"
              data-testid="confirm-post"
              onClick={handlePost}
              disabled={postMutation.isPending}
              className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {postMutation.isPending ? 'Posting...' : 'Post'}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleDelete}
        title="Delete Transaction"
        message="Are you sure you want to delete this transaction? This action cannot be undone."
        variant="danger"
      />
    </div>
  );
}
