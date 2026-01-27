import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useAdvance, useDeleteAdvance, usePostAdvance } from '../hooks/useAdvances';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useCropCycles } from '../hooks/useCropCycles';
import { useFormatting } from '../hooks/useFormatting';
import { v4 as uuidv4 } from 'uuid';

export default function AdvanceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: advance, isLoading } = useAdvance(id || '');
  const deleteMutation = useDeleteAdvance();
  const postMutation = usePostAdvance();
  const { data: cropCycles } = useCropCycles();
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [cropCycleId, setCropCycleId] = useState('');
  const [idempotencyKey] = useState(uuidv4());

  const isDraft = advance?.status === 'DRAFT';
  const canPost = hasRole(['tenant_admin', 'accountant']);

  const getTypeLabel = (type?: string) => {
    switch (type) {
      case 'HARI_ADVANCE':
        return 'Hari Advance';
      case 'VENDOR_ADVANCE':
        return 'Vendor Advance';
      case 'LOAN':
        return 'Loan';
      default:
        return type || 'N/A';
    }
  };

  const handleDelete = async () => {
    if (!id) return;
    try {
      await deleteMutation.mutateAsync(id);
      navigate('/app/advances');
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
          crop_cycle_id: advance?.project_id ? undefined : cropCycleId || undefined,
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

  if (!advance) {
    return <div>Advance not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/advances" className="text-[#1F6F5C] hover:text-[#1a5a4a] mb-2 inline-block">
          ← Back to Advances
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Advance Details</h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Type</dt>
            <dd className="text-sm text-gray-900">{getTypeLabel(advance.type)}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Direction</dt>
            <dd className="text-sm text-gray-900">{advance.direction}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span className={`px-2 py-1 rounded text-xs ${
                advance.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'
              }`}>
                {advance.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Party</dt>
            <dd className="text-sm text-gray-900">{advance.party?.name || advance.party_id}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900"><span className="tabular-nums">{formatMoney(advance.amount)}</span></dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
            <dd className="text-sm text-gray-900">{formatDate(advance.posting_date)}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Method</dt>
            <dd className="text-sm text-gray-900">{advance.method}</dd>
          </div>
          {advance.project && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Project</dt>
              <dd className="text-sm text-gray-900">{advance.project.name}</dd>
            </div>
          )}
          {advance.crop_cycle && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{advance.crop_cycle.name}</dd>
            </div>
          )}
          {advance.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Notes</dt>
              <dd className="text-sm text-gray-900">{advance.notes}</dd>
            </div>
          )}
          {advance.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${advance.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  {advance.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {advance.posted_at && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posted At</dt>
              <dd className="text-sm text-gray-900">{formatDateTime(advance.posted_at)}</dd>
            </div>
          )}
        </dl>
      </div>

      {isDraft && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <div className="flex justify-between items-center">
            <div>
              <h3 className="text-lg font-medium text-gray-900 mb-2">Actions</h3>
              <p className="text-sm text-gray-500">This advance is in DRAFT status and can be edited or posted.</p>
            </div>
            <div className="flex space-x-4">
              <Link
                to={`/app/advances/${id}/edit`}
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

      <Modal
        isOpen={showPostModal}
        onClose={() => setShowPostModal(false)}
        title="Post Advance"
      >
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </FormField>

          {!advance.project_id && (
            <FormField label="Crop Cycle">
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
              {postMutation.isPending ? 'Posting...' : 'Post'}
            </button>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={handleDelete}
        title="Delete Advance"
        message="Are you sure you want to delete this advance? This action cannot be undone."
      />
    </div>
  );
}
