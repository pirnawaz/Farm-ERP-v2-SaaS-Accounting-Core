import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { salesSettlementApi, type SalesSettlement } from '../api/settlement';
import { LoadingSpinner } from '../components/LoadingSpinner';
import { Modal } from '../components/Modal';
import { FormField } from '../components/FormField';
import { ConfirmDialog } from '../components/ConfirmDialog';
import { useRole } from '../hooks/useRole';
import { useFormatting } from '../hooks/useFormatting';
import toast from 'react-hot-toast';

export default function SettlementDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { hasRole } = useRole();
  const { formatMoney, formatDateTime } = useFormatting();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reversalDate, setReversalDate] = useState(new Date().toISOString().split('T')[0]);

  const { data: settlement, isLoading } = useQuery({
    queryKey: ['settlement', id],
    queryFn: () => salesSettlementApi.get(id!),
    enabled: !!id,
  });

  const postMutation = useMutation({
    mutationFn: ({ id, postingDate }: { id: string; postingDate: string }) =>
      salesSettlementApi.post(id, postingDate),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settlement', id] });
      queryClient.invalidateQueries({ queryKey: ['settlements'] });
      toast.success('Settlement posted successfully');
      setShowPostModal(false);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to post settlement');
    },
  });

  const reverseMutation = useMutation({
    mutationFn: ({ id, reversalDate }: { id: string; reversalDate: string }) =>
      salesSettlementApi.reverse(id, reversalDate),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settlement', id] });
      queryClient.invalidateQueries({ queryKey: ['settlements'] });
      toast.success('Settlement reversed successfully');
      setShowReverseModal(false);
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.error || 'Failed to reverse settlement');
    },
  });

  const isDraft = settlement?.status === 'DRAFT';
  const isPosted = settlement?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);

  const handlePost = () => {
    if (!id) return;
    postMutation.mutate({ id, postingDate });
  };

  const handleReverse = () => {
    if (!id) return;
    reverseMutation.mutate({ id, reversalDate });
  };

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!settlement) {
    return <div>Settlement not found</div>;
  }

  return (
    <div>
      <div className="mb-6">
        <Link to="/app/settlements" className="text-blue-600 hover:text-blue-900 mb-2 inline-block">
          ← Back to Settlements
        </Link>
        <h1 className="text-2xl font-bold text-gray-900 mt-2">Settlement Details</h1>
      </div>

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Settlement No</dt>
            <dd className="text-sm text-gray-900">{settlement.settlement_no}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span className={`px-2 py-1 rounded text-xs ${
                settlement.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
                settlement.status === 'POSTED' ? 'bg-green-100 text-green-800' :
                'bg-red-100 text-red-800'
              }`}>
                {settlement.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Basis Amount</dt>
            <dd className="text-sm text-gray-900">{formatMoney(parseFloat(settlement.basis_amount))}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Share Rule</dt>
            <dd className="text-sm text-gray-900">{settlement.share_rule?.name || '-'}</dd>
          </div>
          {settlement.crop_cycle && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Crop Cycle</dt>
              <dd className="text-sm text-gray-900">{settlement.crop_cycle.name}</dd>
            </div>
          )}
          {settlement.posting_date && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
              <dd className="text-sm text-gray-900">{settlement.posting_date}</dd>
            </div>
          )}
          {settlement.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${settlement.posting_group_id}`}
                  className="text-blue-600 hover:text-blue-900"
                >
                  {settlement.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
        </dl>
      </div>

      {/* Settlement Lines */}
      {settlement.lines && settlement.lines.length > 0 && (
        <div className="bg-white rounded-lg shadow p-6 mb-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Distribution</h2>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Party</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Percentage</th>
                  <th className="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {settlement.lines.map((line) => (
                  <tr key={line.id}>
                    <td className="px-4 py-2 text-sm text-gray-900">{line.party?.name || line.party_id}</td>
                    <td className="px-4 py-2 text-sm text-gray-900">{line.role || '-'}</td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right">{parseFloat(line.percentage).toFixed(2)}%</td>
                    <td className="px-4 py-2 text-sm text-gray-900 text-right">{formatMoney(parseFloat(line.amount))}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Actions */}
      {canPost && (
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-medium text-gray-900 mb-4">Actions</h2>
          <div className="flex gap-2">
            {isDraft && (
              <button
                onClick={() => setShowPostModal(true)}
                className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
              >
                Post Settlement
              </button>
            )}
            {isPosted && (
              <button
                onClick={() => setShowReverseModal(true)}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
              >
                Reverse Settlement
              </button>
            )}
          </div>
        </div>
      )}

      {/* Post Modal */}
      {showPostModal && (
        <Modal title="Post Settlement" onClose={() => setShowPostModal(false)}>
          <div className="space-y-4">
            <FormField
              label="Posting Date"
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
            />
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowPostModal(false)}
                className="px-4 py-2 border rounded"
              >
                Cancel
              </button>
              <button
                onClick={handlePost}
                className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                disabled={postMutation.isPending}
              >
                Post
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* Reverse Modal */}
      {showReverseModal && (
        <Modal title="Reverse Settlement" onClose={() => setShowReverseModal(false)}>
          <div className="space-y-4">
            <p className="text-sm text-gray-600">
              This will create a reversal posting group that negates all accounting entries from this settlement.
            </p>
            <FormField
              label="Reversal Date"
              type="date"
              value={reversalDate}
              onChange={(e) => setReversalDate(e.target.value)}
            />
            <div className="flex justify-end gap-2 mt-6">
              <button
                onClick={() => setShowReverseModal(false)}
                className="px-4 py-2 border rounded"
              >
                Cancel
              </button>
              <button
                onClick={handleReverse}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
                disabled={reverseMutation.isPending}
              >
                Reverse
              </button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
