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
import { TraceabilityPanel } from '../../components/traceability/TraceabilityPanel';
import { PrePostChecklist } from '../../components/operator/PrePostChecklist';
import { OperatorErrorCallout } from '../../components/operator/OperatorErrorCallout';
import { formatOperatorError } from '../../utils/operatorFriendlyErrors';

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
  const [reverseDate, setReverseDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  useEffect(() => {
    if (log && !showPostModal && !showReverseModal) {
      const today = new Date().toISOString().split('T')[0];
      setPostingDate(today);
      setReverseDate(today);
    }
  }, [log, showPostModal, showReverseModal]);

  const isDraft = log?.status === 'DRAFT';
  const isPosted = log?.status === 'POSTED';
  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const usageNum = log ? parseFloat(String(log.usage_qty ?? '')) : NaN;
  const rateNum = log ? parseFloat(String(log.internal_charge_rate ?? '')) : NaN;
  const chargeableIncomplete =
    !!log?.chargeable &&
    isDraft &&
    (!log.project_id || !(usageNum > 0) || !(rateNum > 0));

  const canConfirmWorkLogReverse = Boolean(reverseDate && id);

  const handlePost = async () => {
    if (!id) return;
    try {
      await postM.mutateAsync({ id, posting_date: postingDate });
      setShowPostModal(false);
      postM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
  };

  const handleReverse = async () => {
    if (!id || !canConfirmWorkLogReverse) return;
    try {
      await reverseM.mutateAsync({
        id,
        posting_date: reverseDate,
        reason: reverseReason.trim() || undefined,
      });
      setShowReverseModal(false);
      setReverseReason('');
      reverseM.reset();
    } catch {
      /* OperatorErrorCallout */
    }
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

      <TraceabilityPanel traceability={log.traceability} />

      {log.chargeable && log.status === 'POSTED' && (
        <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-950">
          <p className="font-medium">This usage was posted with a project charge</p>
          <p className="mt-1">
            The field cycle was charged for machine work, and this machine recorded matching service income
            {log.internal_charge_amount != null && log.internal_charge_amount !== '' ? (
              <> ({formatMoney(log.internal_charge_amount)})</>
            ) : null}
            .
          </p>
        </div>
      )}

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
            <dt className="text-sm text-gray-500">Project (field cycle)</dt>
            <dd>{log.project?.name ?? log.project_id ?? '—'}</dd>
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
          {log.chargeable && (
            <>
              <div>
                <dt className="text-sm text-gray-500">Charge this job to project</dt>
                <dd>Yes — internal machine work rate</dd>
              </div>
              <div>
                <dt className="text-sm text-gray-500">Rate (per meter unit)</dt>
                <dd className="tabular-nums">
                  {log.internal_charge_rate != null && log.internal_charge_rate !== '' ? String(log.internal_charge_rate) : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-gray-500">Posted charge</dt>
                <dd className="tabular-nums">
                  {log.internal_charge_amount != null && log.internal_charge_amount !== ''
                    ? formatMoney(log.internal_charge_amount)
                    : '—'}
                </dd>
              </div>
            </>
          )}
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
            onClick={() => {
              postM.reset();
              setShowPostModal(true);
            }}
            disabled={chargeableIncomplete}
            title={
              chargeableIncomplete
                ? 'Set field cycle, usage quantity, and charge rate before recording a chargeable log.'
                : undefined
            }
            className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed min-h-[44px]"
          >
            Record to accounts
          </button>
          {chargeableIncomplete ? (
            <p className="mt-2 text-sm text-amber-800 max-w-xl">
              This log is chargeable but missing project, usage, or rate. Edit the draft before recording to accounts.
            </p>
          ) : null}
        </div>
      )}

      {isPosted && canPost && (
        <div>
          <button
            type="button"
            onClick={() => {
              reverseM.reset();
              setReverseReason('');
              setShowReverseModal(true);
            }}
            className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 min-h-[44px]"
          >
            Reverse usage
          </button>
        </div>
      )}

      <Modal
        isOpen={showPostModal}
        onClose={() => {
          setShowPostModal(false);
          postM.reset();
        }}
        title="Record machine usage to accounts"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            {log.chargeable
              ? 'This will record costs and update accounts: the field cycle is charged for the machine work, and this machine shows matching service income.'
              : 'This will record costs and update accounts for this usage and its cost lines. No field-cycle charge applies for non-chargeable usage.'}
          </p>
          <PrePostChecklist
            items={[
              { ok: Boolean(postingDate), label: 'Posting date chosen' },
              {
                ok: !chargeableIncomplete,
                label: log.chargeable
                  ? 'Chargeable rules: field cycle, usage > 0, rate > 0'
                  : 'No field-cycle charge for this entry',
              },
            ]}
            blockingHint={
              chargeableIncomplete || !postingDate
                ? 'Complete required fields before recording.'
                : undefined
            }
          />
          {chargeableIncomplete ? (
            <p className="text-sm text-red-700" role="alert">
              Cannot record: add field cycle, usage quantity, and internal charge rate on the draft first.
            </p>
          ) : null}
          <OperatorErrorCallout error={postM.isError ? formatOperatorError(postM.error) : null} />
          <FormField label="Posting date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
            />
          </FormField>
          <div className="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => {
                setShowPostModal(false);
                postM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending || chargeableIncomplete || !postingDate}
              className="w-full sm:w-auto px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50 disabled:cursor-not-allowed min-h-[44px]"
            >
              {postM.isPending ? 'Recording…' : 'Confirm'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={showReverseModal}
        onClose={() => {
          setShowReverseModal(false);
          setReverseReason('');
          reverseM.reset();
        }}
        title="Reverse machine usage"
      >
        <div className="space-y-4">
          <p className="text-sm text-gray-700 leading-relaxed">
            This creates offsetting entries as of the posting date below. Cancel if you are not ready.
          </p>
          <PrePostChecklist
            items={[{ ok: Boolean(reverseDate), label: 'Posting date chosen' }]}
            blockingHint={!reverseDate ? 'Choose a posting date before reversing.' : undefined}
          />
          <OperatorErrorCallout error={reverseM.isError ? formatOperatorError(reverseM.error) : null} />
          <FormField label="Posting date" required>
            <input
              type="date"
              value={reverseDate}
              onChange={(e) => setReverseDate(e.target.value)}
              className="w-full px-3 py-2 border rounded min-h-[44px]"
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
                reverseM.reset();
              }}
              className="w-full sm:w-auto px-4 py-2 border rounded min-h-[44px]"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={reverseM.isPending || !canConfirmWorkLogReverse}
              className="w-full sm:w-auto px-4 py-2 bg-red-600 text-white rounded disabled:opacity-50 min-h-[44px]"
            >
              {reverseM.isPending ? 'Reversing…' : 'Confirm reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
