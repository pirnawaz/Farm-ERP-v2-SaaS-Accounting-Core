import { useState, useEffect } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import {
  useMaintenanceJobQuery,
  useUpdateMaintenanceJob,
  usePostMaintenanceJob,
  useReverseMaintenanceJob,
} from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { PageHeader } from '../../components/PageHeader';
import { v4 as uuidv4 } from 'uuid';

export default function MaintenanceJobDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { formatMoney, formatDate } = useFormatting();
  const { hasRole } = useRole();
  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');
  const [editedLines, setEditedLines] = useState<Record<string, { description: string; amount: number }>>({});

  const { data: job, isLoading } = useMaintenanceJobQuery(id!);
  const updateMutation = useUpdateMaintenanceJob();
  const postMutation = usePostMaintenanceJob();
  const reverseMutation = useReverseMaintenanceJob();

  const canEdit = hasRole(['tenant_admin', 'accountant']);
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const isDraft = job?.status === 'DRAFT';
  const isPosted = job?.status === 'POSTED';

  // Initialize edited lines from job lines
  useEffect(() => {
    if (job?.lines && isDraft) {
      const initial: Record<string, { description: string; amount: number }> = {};
      job.lines.forEach((line) => {
        initial[line.id] = {
          description: line.description || '',
          amount: parseFloat(line.amount),
        };
      });
      setEditedLines(initial);
    }
  }, [job?.lines, isDraft]);

  const handleLineChange = (lineId: string, field: 'description' | 'amount', value: string | number) => {
    if (!isDraft) return;
    setEditedLines((prev) => {
      const updated = { ...prev };
      if (!updated[lineId]) {
        updated[lineId] = { description: '', amount: 0 };
      }
      updated[lineId][field] = value;
      return updated;
    });
  };

  const handleSave = async () => {
    if (!id || !isDraft) return;

    const lines = Object.entries(editedLines).map(([lineId, values]) => ({
      id: lineId,
      description: values.description || undefined,
      amount: values.amount,
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
          idempotency_key: uuidv4(),
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

  // Calculate total from edited lines or job
  const totalAmount =
    isDraft && Object.keys(editedLines).length > 0
      ? Object.values(editedLines).reduce((sum, line) => sum + line.amount, 0)
      : parseFloat(job?.total_amount || '0');

  if (isLoading) {
    return (
      <div className="flex justify-center items-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  if (!job) {
    return <div>Maintenance job not found</div>;
  }

  return (
    <div>
      <PageHeader
        title={`Maintenance Job ${job.job_no}`}
        backTo="/app/machinery/maintenance-jobs"
        breadcrumbs={[
          { label: 'Machinery', to: '/app/machinery' },
          { label: 'Maintenance Jobs', to: '/app/machinery/maintenance-jobs' },
          { label: job.job_no },
        ]}
      />

      {/* Header Info */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm font-medium text-gray-500">Job No</dt>
            <dd className="text-sm text-gray-900 font-semibold">{job.job_no}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Status</dt>
            <dd className="text-sm text-gray-900">
              <span
                className={`px-2 py-1 rounded text-xs ${
                  job.status === 'DRAFT'
                    ? 'bg-yellow-100 text-yellow-800'
                    : job.status === 'POSTED'
                    ? 'bg-green-100 text-green-800'
                    : 'bg-red-100 text-red-800'
                }`}
              >
                {job.status}
              </span>
            </dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Machine</dt>
            <dd className="text-sm text-gray-900">{job.machine?.code} - {job.machine?.name}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Maintenance Type</dt>
            <dd className="text-sm text-gray-900">{job.maintenance_type?.name || 'N/A'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Vendor Party</dt>
            <dd className="text-sm text-gray-900">{job.vendor_party?.name || 'N/A'}</dd>
          </div>
          <div>
            <dt className="text-sm font-medium text-gray-500">Job Date</dt>
            <dd className="text-sm text-gray-900">{formatDate(job.job_date)}</dd>
          </div>
          {job.posting_date && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Date</dt>
              <dd className="text-sm text-gray-900">{formatDate(job.posting_date)}</dd>
            </div>
          )}
          {job.posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${job.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
          {job.reversal_posting_group_id && (
            <div>
              <dt className="text-sm font-medium text-gray-500">Reversal Posting Group</dt>
              <dd className="text-sm text-gray-900">
                <Link
                  to={`/app/posting-groups/${job.reversal_posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  View
                </Link>
              </dd>
            </div>
          )}
          {job.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm font-medium text-gray-500">Notes</dt>
              <dd className="text-sm text-gray-900">{job.notes}</dd>
            </div>
          )}
        </dl>
      </div>

      {/* Lines Table */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Job Lines</h2>
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Description
                </th>
                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Amount
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {job.lines?.map((line) => {
                const edited = editedLines[line.id];
                const description = edited ? edited.description : line.description || '';
                const amount = edited ? edited.amount : parseFloat(line.amount);
                return (
                  <tr key={line.id}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {description || '—'}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                      <span className="tabular-nums">{formatMoney(amount)}</span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot className="bg-gray-50">
              <tr>
                <td className="px-6 py-4 text-right text-sm font-medium text-gray-900">Total:</td>
                <td className="px-6 py-4 text-right text-sm font-medium text-gray-900 tabular-nums">
                  {formatMoney(totalAmount)}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      {/* Actions */}
      <div className="bg-white rounded-lg shadow p-6 mb-6">
        <h2 className="text-lg font-medium text-gray-900 mb-4">Actions</h2>
        <div className="flex gap-2">
          {isDraft && canEdit && (
            <>
              <button
                onClick={() => navigate(`/app/machinery/maintenance-jobs/${id}/edit`)}
                className="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700"
              >
                Edit
              </button>
              {canPost && (
                <button
                  onClick={() => setShowPostModal(true)}
                  className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
                >
                  Post Job
                </button>
              )}
            </>
          )}
          {isPosted && canPost && (
            <button
              onClick={() => setShowReverseModal(true)}
              className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
            >
              Reverse Job
            </button>
          )}
        </div>
      </div>

      {/* Post Modal */}
      {showPostModal && (
        <Modal title="Post Maintenance Job" onClose={() => setShowPostModal(false)}>
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
        <Modal title="Reverse Maintenance Job" onClose={() => setShowReverseModal(false)}>
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
