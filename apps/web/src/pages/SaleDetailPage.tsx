import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useSale, useDeleteSale, usePostSale } from '../hooks/useSales';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: sale, isLoading } = useSale(id || '');
  const deleteMutation = useDeleteSale();
  const postMutation = usePostSale();
  const { hasRole } = useRole();
  const { formatMoney, formatDateTime } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [idempotencyKey] = useState(uuidv4());

  const isDraft = sale?.status === 'DRAFT';
  const canPost = hasRole(['tenant_admin', 'accountant']);

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(id);
      navigate('/app/sales');
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
        },
      });
      setShowPostModal(false);
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

  if (!sale) {
    return <div>Sale not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/sales" className="text-blue-600 hover:text-blue-900 mb-2 inline-block">
          ← Back to Sales
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Sale Details</h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span className={`px-2 py-1 rounded text-xs ${
                sale.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
              }`}>
                {sale.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Buyer</dt>
            <dd className="text-sm text-gray-900">{sale.buyer_party?.name || sale.buyer_party_id}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900">{formatMoney(sale.amount)}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
            <dd className="text-sm text-gray-900">{sale.posting_date}</dd>
          </div>
          {sale.project && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Project</dt>
              <dd className="text-sm text-gray-900">{sale.project.name}</dd>
            </div>
          )}
          {sale.crop_cycle && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{sale.crop_cycle.name}</dd>
            </div>
          )}
          {sale.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Notes</dt>
              <dd className="text-sm text-gray-900">{sale.notes}</dd>
            </div>
          )}
          {sale.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${sale.posting_group_id}`}
                  className="text-blue-600 hover:text-blue-900"
                >
                  {sale.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {sale.posted_at && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posted At</dt>
              <dd className="text-sm text-gray-900">{formatDateTime(sale.posted_at)}</dd>
            </div>
          )}
        </dl>
      </div>

      {isDraft && (
        <div className="bg-white rounded-lg shadow p-6">
          <div className="flex justify-between items-center">
            <div>
              <h2 className="text-lg font-medium text-gray-900 mb-2">Actions</h2>
              <p className="text-sm text-gray-600">
                This sale is in DRAFT status. Post it to create accounting entries.
              </p>
            </div>
            <div className="flex space-x-4">
              <Link
                to={`/app/sales/${id}/edit`}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Edit
              </Link>
              {canPost && (
                <button
                  onClick={() => setShowPostModal(true)}
                  className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                >
                  Post
                </button>
              )}
              <button
                onClick={() => setShowDeleteConfirm(true)}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}

      {showPostModal && (
        <Modal
          isOpen={showPostModal}
          title="Post Sale"
          onClose={() => setShowPostModal(false)}
        >
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </FormField>
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <p className="text-sm text-blue-800">
                <strong>Note:</strong> Posting will create:
                <ul className="list-disc list-inside mt-2">
                  <li>Debit: Accounts Receivable (AR)</li>
                  <li>Credit: Project Revenue</li>
                  <li>Allocation row for revenue tracking</li>
                </ul>
              </p>
            </div>
            <div className="flex justify-end space-x-4 pt-4">
              <button
                onClick={() => setShowPostModal(false)}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50"
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                disabled={postMutation.isPending}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50"
              >
                {postMutation.isPending ? 'Posting...' : 'Post Sale'}
              </button>
            </div>
          </div>
        </Modal>
      )}

      {showDeleteConfirm && (
        <ConfirmDialog
          isOpen={showDeleteConfirm}
          onClose={() => setShowDeleteConfirm(false)}
          title="Delete Sale"
          message="Are you sure you want to delete this sale? This action cannot be undone."
          onConfirm={handleDelete}
          confirmText="Delete"
          variant="danger"
        />
      )}
    </div>
  );
}
