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
import { Term } from '../../components/Term';
import { formatItemDisplayName } from '../../utils/formatItemDisplay';
import { term } from '../../config/terminology';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';

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
      <div className="space-y-6">
        <PageHeader
          title="Service record"
          backTo="/app/machinery/services"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Service History', to: '/app/machinery/services' },
            { label: '…' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      </div>
    );
  }

  if (!service) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Service record"
          backTo="/app/machinery/services"
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Service History', to: '/app/machinery/services' },
            { label: 'Not found' },
          ]}
        />
        <p className="text-gray-600">Service not found.</p>
        <button type="button" onClick={() => navigate('/app/machinery/services')} className="text-[#1F6F5C] font-medium hover:underline">
          Back to service history
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Service record"
        description="Historical servicing entry for a machine, with field cycle context and allocation."
        helper="Distinct from machine usage logs—service history records servicing work and amounts."
        backTo="/app/machinery/services"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Service History', to: '/app/machinery/services' },
          { label: service.id.slice(0, 8) + '…' },
        ]}
        right={
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 w-full sm:w-auto">
            {isDraft && canEdit && (
              <button
                type="button"
                onClick={() => navigate(`/app/machinery/services/${id}/edit`)}
                className="w-full sm:w-auto px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50"
              >
                Edit
              </button>
            )}
            {isDraft && canPost && (
              <button
                type="button"
                data-testid="post-btn"
                onClick={() => setShowPostModal(true)}
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
              >
                Post
              </button>
            )}
            {isPosted && canPost && (
              <button
                type="button"
                data-testid="create-correction-btn"
                onClick={() => setShowReverseModal(true)}
                className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
              >
                Reverse
              </button>
            )}
          </div>
        }
      />

      <div className="bg-white rounded-lg shadow p-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900" data-testid="status-badge">
              <PostingStatusBadge status={service.status} />
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
              <dd className="text-sm text-gray-900 tabular-nums">{formatDate(service.posting_date, { variant: 'medium' })}</dd>
            </div>
          )}
          <div>
            <dt className="text-sm font-medium text-gray-500">{term('fieldCycle')}</dt>
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
                ? `${formatDate(service.rate_card.effective_from)} @ ${formatMoney(service.rate_card.base_rate)}/${service.rate_card.rate_unit}`
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
                {formatItemDisplayName(service.in_kind_item)}
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
              <dt className="text-sm font-medium text-gray-500"><Term k="postingGroup" showHint /></dt>
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
              <dt className="text-sm font-medium text-gray-500"><Term k="reversalPostingGroup" showHint /></dt>
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
            {(() => {
              const msg = (postMutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message;
              return msg ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{msg}</div> : null;
            })()}
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
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
              <button
                type="button"
                onClick={() => setShowPostModal(false)}
                className="w-full sm:w-auto px-4 py-2 border rounded"
                disabled={postMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                data-testid="confirm-post"
                onClick={handlePost}
                className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
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
            {(() => {
              const msg = (reverseMutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message;
              return msg ? <div className="rounded-md bg-red-50 p-3 text-sm text-red-700">{msg}</div> : null;
            })()}
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
            <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 mt-6">
              <button
                type="button"
                onClick={() => {
                  setShowReverseModal(false);
                  setReverseReason('');
                }}
                className="w-full sm:w-auto px-4 py-2 border rounded"
                disabled={reverseMutation.isPending}
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleReverse}
                className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
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
