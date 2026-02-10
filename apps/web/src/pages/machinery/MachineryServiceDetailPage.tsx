import { useState } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import {
  useMachineryServiceQuery,
  usePostMachineryService,
  useReverseMachineryService,
} from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { PageHeader } from '../../components/PageHeader';
import { v4 as uuidv4 } from 'uuid';

export default function MachineryServiceDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { formatMoney, formatDate } = useFormatting();
  const { hasRole } = useRole();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const { data: service, isLoading } = useMachineryServiceQuery(id!);
  const postMutation = usePostMachineryService();
  const reverseMutation = useReverseMachineryService();

  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const isDraft = service?.status === 'DRAFT';
  const isPosted = service?.status === 'POSTED';

  const handlePost = async () => {
    if (!id) return;
    try {
      await postMutation.mutateAsync({
        id,
        payload: { posting_date: postingDate, idempotency_key: uuidv4() },
      });
      setShowPostModal(false);
    } catch {
      // Error handled by mutation
    }
  };

  const handleReverse = async () => {
    if (!id) return;
    try {
      await reverseMutation.mutateAsync({
        id,
        payload: { posting_date: reverseDate, reason: reverseReason || undefined },
      });
      setShowReverseModal(false);
      setReverseReason('');
    } catch {
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

  if (!service) {
    return (
      <div className="p-6">
        <p>Service not found.</p>
        <button onClick={() => navigate('/app/machinery/services')} className="mt-4 text-[#1F6F5C] hover:underline">
          Back to Services
        </button>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Machinery Service"
        backTo="/app/machinery/services"
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Services', to: '/app/machinery/services' },
          { label: service.id.slice(0, 8) + '…' },
        ]}
        right={
          <>
            {isDraft && canEdit && (
              <button
                onClick={() => navigate(`/app/machinery/services/${id}/edit`)}
                className="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 mr-2"
              >
                Edit
              </button>
            )}
            {isDraft && canPost && (
              <button
                type="button"
                data-testid="post-btn"
                onClick={() => setShowPostModal(true)}
                className="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
              >
                Post
              </button>
            )}
            {isPosted && canPost && (
              <button
                type="button"
                data-testid="create-correction-btn"
                onClick={() => setShowReverseModal(true)}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              >
                Reverse
              </button>
            )}
          </>
        }
      />

      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span
                data-testid="status-badge"
                className={`px-2 py-1 rounded text-xs ${
                  service.status === 'DRAFT'
                    ? 'bg-yellow-100 text-yellow-800'
                    : service.status === 'POSTED'
                      ? 'bg-green-100 text-green-800'
                      : 'bg-red-100 text-red-800'
                }`}
              >
                {service.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Amount</dt>
            <dd className="text-sm text-gray-900 font-semibold tabular-nums">
              {service.amount != null ? formatMoney(service.amount) : '—'}
            </dd>
          </div>
          {service.posting_date && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
              <dd className="text-sm text-gray-900">{formatDate(service.posting_date)}</dd>
            </div>
          )}
          <div>
            <dt className="text-sm font-medium text-gray-500">Project</dt>
            <dd className="text-sm text-gray-900">{service.project?.name ?? service.project_id ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Machine</dt>
            <dd className="text-sm text-gray-900">
              {service.machine ? `${service.machine.code} – ${service.machine.name}` : service.machine_id}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Rate card</dt>
            <dd className="text-sm text-gray-900">
              {service.rate_card
                ? `${service.rate_card.effective_from} @ ${formatMoney(service.rate_card.base_rate)}/${service.rate_card.rate_unit}`
                : service.rate_card_id ?? '—'}
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Quantity</dt>
            <dd className="text-sm text-gray-900">{service.quantity ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Allocation scope</dt>
            <dd className="text-sm text-gray-900">{service.allocation_scope}</dd>
          </div>
          {(service.in_kind_item_id || service.in_kind_quantity) && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">In-kind payment</dt>
              <dd className="text-sm text-gray-900">
                {service.in_kind_item?.name ?? service.in_kind_item_id}
                {service.in_kind_rate_per_unit != null && ` @ ${service.in_kind_rate_per_unit} per unit`}
                {service.in_kind_quantity != null && ` → total ${service.in_kind_quantity}`}
                {service.in_kind_inventory_issue_id && (
                  <span className="ml-2">
                    <Link
                      to={`/app/inventory/issues/${service.in_kind_inventory_issue_id}`}
                      className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                    >
                      View issue
                    </Link>
                  </span>
                )}
              </dd>
            </div>
          )}
          {service.posting_group_id && (
            <div data-testid="posting-group-panel">
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${service.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                  data-testid="posting-group-id"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
          {service.reversal_posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Reversal Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${service.reversal_posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
          {service.project_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Settlement</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/settlement?project_id=${service.project_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View settlement
                </Link>
              </dd>
            </div>
          )}
        </dl>
      </div>

      {showPostModal && (
        <Modal isOpen={true} title="Post Service" onClose={() => setShowPostModal(false)} testId="posting-date-modal">
          <div className="space-y-4">
            <FormField label="Posting Date" required>
              <input
                type="date"
                data-testid="posting-date-input"
                value={postingDate}
                onChange={(e) => setPostingDate(e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
                required
              />
            </FormField>
            <div className="flex justify-end gap-2 mt-6">
              <button
                type="button"
                onClick={() => setShowPostModal(false)}
                className="px-4 py-2 border rounded"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                data-testid="confirm-post"
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

      {showReverseModal && (
        <Modal
          isOpen={true}
          title="Reverse Service"
          onClose={() => {
            setShowReverseModal(false);
            setReverseReason('');
          }}
        >
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
                onClick={() => {
                  setShowReverseModal(false);
                  setReverseReason('');
                }}
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
