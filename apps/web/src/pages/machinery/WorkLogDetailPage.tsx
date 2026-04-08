import { useState, useEffect } from 'react';
import { useParams, useLocation, Link } from 'react-router-dom';
import {
  useWorkLogQuery,
  usePostWorkLog,
  useReverseWorkLog,
} from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import { PostingStatusBadge } from '../../utils/postingStatusDisplay';

export default function WorkLogDetailPage() {
  const { id } = useParams<{ id: string }>();
  const location = useLocation();
  const { data: log, isLoading } = useWorkLogQuery(id ?? '');
  const from = (location.state as { from?: string } | null)?.from;
  const backTo = from ?? '/app/machinery/work-logs';

  const postM = usePostWorkLog();
  const reverseM = useReverseWorkLog();
  const { hasRole } = useRole();
  const { formatMoney, formatDate } = useFormatting();

  const [showPostModal, setShowPostModal] = useState(false);
  const [showReverseModal, setShowReverseModal] = useState(false);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  useEffect(() => {
    if (log && !showPostModal && !showReverseModal) {
      setPostingDate(new Date().toISOString().split('T')[0]);
    }
  }, [log, showPostModal, showReverseModal]);

  const isDraft = log?.status === 'DRAFT';
  const isPosted = log?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const handlePost = async () => {
    if (!id) return;
    await postM.mutateAsync({ id, posting_date: postingDate });
    setShowPostModal(false);
  };

  const handleReverse = async () => {
    if (!id) return;
    await reverseM.mutateAsync({
      id,
      posting_date: postingDate,
      reason: reverseReason.trim() || undefined,
    });
    setShowReverseModal(false);
    setReverseReason('');
  };

  if (isLoading) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Machine usage"
          backTo={backTo}
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
            { label: '…' },
          ]}
        />
        <div className="flex justify-center py-12">
          <LoadingSpinner size="lg" />
        </div>
      </div>
    );
  }
  if (!log) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Machine usage"
          backTo={backTo}
          breadcrumbs={[
            { label: 'Farm', to: '/app/dashboard' },
            { label: 'Machinery Overview', to: '/app/machinery' },
            { label: 'Machine Usage', to: '/app/machinery/work-logs' },
            { label: 'Not found' },
          ]}
        />
        <p className="text-gray-600">Machine usage record not found.</p>
        <Link to="/app/machinery/work-logs" className="text-[#1F6F5C] font-medium hover:underline">
          Back to machine usage
        </Link>
      </div>
    );
  }

  const totalAmount = (log.lines ?? []).reduce(
    (s, l) => s + parseFloat(String(l.amount ?? 0)),
    0
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Machine usage ${log.work_log_no}`}
        description="Machine hours or usage linked to a field cycle, with cost lines for fuel, operator, or maintenance."
        helper="This is usage and costing—not a maintenance job or service history record."
        backTo={backTo}
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Machinery Overview', to: '/app/machinery' },
          { label: 'Machine Usage', to: '/app/machinery/work-logs' },
          { label: log.work_log_no },
        ]}
        right={
          canEdit && isDraft ? (
            <Link
              to={`/app/machinery/work-logs/${log.id}/edit`}
              className="inline-flex justify-center w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
            >
              Edit
            </Link>
          ) : undefined
        }
      />

      <div className="bg-white rounded-lg shadow p-6">
        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <dt className="text-sm text-gray-500">Usage no.</dt>
            <dd className="font-medium tabular-nums">{log.work_log_no}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Machine</dt>
            <dd>{log.machine?.name ?? log.machine_id}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Field cycle</dt>
            <dd>{log.project?.name ?? log.project_id}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Crop cycle</dt>
            <dd>{log.crop_cycle?.name ?? log.crop_cycle_id ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Work date</dt>
            <dd className="tabular-nums">{log.work_date ? formatDate(log.work_date, { variant: 'medium' }) : '—'}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Meter start / end</dt>
            <dd>
              {log.meter_start != null || log.meter_end != null
                ? `${log.meter_start ?? '—'} / ${log.meter_end ?? '—'}`
                : '—'}
            </dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Usage</dt>
            <dd>{log.usage_qty != null ? String(log.usage_qty) : '—'}</dd>
          </div>
          <div>
            <dt className="text-sm text-gray-500">Status</dt>
            <dd>
              <PostingStatusBadge status={log.status} />
            </dd>
          </div>
          {log.posting_date && (
            <div>
              <dt className="text-sm text-gray-500">Posting date</dt>
              <dd className="tabular-nums">{formatDate(log.posting_date, { variant: 'medium' })}</dd>
            </div>
          )}
          {log.posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Posting group</dt>
              <dd>
                <Link
                  to={`/app/posting-groups/${log.posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  {log.posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {log.reversal_posting_group_id && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Reversal posting group</dt>
              <dd>
                <Link
                  to={`/app/posting-groups/${log.reversal_posting_group_id}`}
                  className="text-[#1F6F5C] hover:text-[#1a5a4a]"
                >
                  {log.reversal_posting_group_id}
                </Link>
              </dd>
            </div>
          )}
          {log.notes && (
            <div className="md:col-span-2">
              <dt className="text-sm text-gray-500">Notes</dt>
              <dd className="whitespace-pre-wrap">{log.notes}</dd>
            </div>
          )}
        </dl>
      </div>

      <div className="bg-white rounded-lg shadow p-6">
        <h3 className="text-sm font-semibold text-gray-900 mb-2">Cost lines</h3>
        <div className="overflow-x-auto">
          <table className="min-w-full border">
            <thead className="bg-[#E6ECEA]">
              <tr>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Cost code</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Description</th>
                <th className="px-3 py-2 text-left text-xs text-gray-500">Party</th>
                <th className="px-3 py-2 text-right text-xs text-gray-500">Amount</th>
              </tr>
            </thead>
            <tbody>
              {(log.lines ?? []).map((l) => (
                <tr key={l.id}>
                  <td className="px-3 py-2">{l.cost_code}</td>
                  <td className="px-3 py-2">{l.description ?? '—'}</td>
                  <td className="px-3 py-2">{l.party?.name ?? '—'}</td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {formatMoney(l.amount)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 font-medium">
          Total: <span className="tabular-nums">{formatMoney(totalAmount)}</span>
        </p>
      </div>

      {isDraft && canPost && (
        <div>
          <button
            type="button"
            onClick={() => setShowPostModal(true)}
            className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
          >
            Post
          </button>
        </div>
      )}

      {isPosted && canPost && (
        <div>
          <button
            type="button"
            onClick={() => {
              setShowReverseModal(true);
              setReverseReason('');
            }}
            className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
          >
            Reverse
          </button>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => setShowPostModal(false)}
        title="Post Work Log"
      >
        <div className="space-y-4">
          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => setShowPostModal(false)}
              className="w-full sm:w-auto px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending}
              className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded"
            >
              {postM.isPending ? 'Posting…' : 'Post'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setReverseReason('');
        }}
        title="Reverse Work Log"
      >
        <div className="space-y-4">
          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>
          <FormField label="Reason (optional)">
            <textarea
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              className="w-full px-3 py-2 border rounded"
              rows={2}
              placeholder="Reason for reversal"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowReverseModal(false);
                setReverseReason('');
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={reverseM.isPending}
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded"
            >
              {reverseM.isPending ? 'Reversing…' : 'Reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
