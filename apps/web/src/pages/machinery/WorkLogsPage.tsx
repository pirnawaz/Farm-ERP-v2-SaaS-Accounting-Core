/**
 * @deprecated Legacy machine usage / work logs UI. Primary machinery capture: Field Jobs (+ charges).
 *             Retained for list/detail, project links, and traceability.
 */
import { useMemo, useState } from 'react';
import { useNavigate, useLocation, useSearchParams } from 'react-router-dom';
import {
  useWorkLogsQuery,
  usePostWorkLog,
  useReverseWorkLog,
  useMachinesQuery,
} from '../../hooks/useMachinery';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { Modal } from '../../components/Modal';
import { FormField } from '../../components/FormField';
import { PageHeader } from '../../components/PageHeader';
import { useRole } from '../../hooks/useRole';
import { useFormatting } from '../../hooks/useFormatting';
import type { MachineWorkLog } from '../../types';
import { Badge } from '../../components/Badge';
import { AdvancedWorkflowBanner } from '../../components/workflow/AdvancedWorkflowBanner';

export default function WorkLogsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [status, setStatus] = useState(searchParams.get('status') ?? '');
  const [machineId, setMachineId] = useState(searchParams.get('machine_id') ?? '');
  const [cropCycleId, setCropCycleId] = useState(searchParams.get('crop_cycle_id') ?? '');
  const [projectId, setProjectId] = useState(searchParams.get('project_id') ?? '');
  const [from, setFrom] = useState(searchParams.get('from') ?? '');
  const [to, setTo] = useState(searchParams.get('to') ?? '');

  const { data: workLogs, isLoading } = useWorkLogsQuery({
    status: status || undefined,
    machine_id: machineId || undefined,
    crop_cycle_id: cropCycleId || undefined,
    project_id: projectId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: machines } = useMachinesQuery();
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);

  const [postTarget, setPostTarget] = useState<MachineWorkLog | null>(null);
  const [reverseTarget, setReverseTarget] = useState<MachineWorkLog | null>(null);
  const [postingDate, setPostingDate] = useState(new Date().toISOString().split('T')[0]);
  const [reverseReason, setReverseReason] = useState('');

  const postM = usePostWorkLog();
  const reverseM = useReverseWorkLog();
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();
  const { hasRole } = useRole();

  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);


  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(searchParams);
    if (!value) next.delete(key);
    else next.set(key, value);
    setSearchParams(next);
  };

  const clearFilters = () => {
    setStatus('');
    setMachineId('');
    setCropCycleId('');
    setProjectId('');
    setFrom('');
    setTo('');
    setSearchParams(new URLSearchParams());
  };

  const hasFilters = !!(status || machineId || cropCycleId || projectId || from || to);

  const cols: Column<MachineWorkLog>[] = [
    {
      header: 'Date',
      accessor: (r) =>
        r.work_date ? (
          <span className="tabular-nums text-gray-900">{formatDate(r.work_date, { variant: 'medium' })}</span>
        ) : (
          '—'
        ),
    },
    { header: 'Machine', accessor: (r) => r.machine?.name ?? r.machine_id },
    { header: 'Field cycle', accessor: (r) => r.project?.name ?? r.project_id },
    {
      header: 'Usage',
      accessor: (r) => <span className="tabular-nums">{r.usage_qty != null ? String(r.usage_qty) : '—'}</span>,
    },
    { header: 'Reference', accessor: (r) => <span className="tabular-nums">{r.work_log_no}</span> },
    {
      header: 'Note',
      accessor: (r) => (r.notes ? <span className="block max-w-[24rem] truncate" title={r.notes}>{r.notes}</span> : '—'),
    },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status === 'DRAFT' ? 'Draft' : r.status === 'POSTED' ? 'Posted' : 'Reversed'}
        </Badge>
      ),
    },
    {
      header: 'Actions',
      accessor: (r) => (
        <div className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
          <button
            type="button"
            onClick={() => navigate(`/app/machinery/work-logs/${r.id}`, { state: { from: location.pathname + location.search } })}
            className="px-2 py-1 text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
          >
            View
          </button>
          {r.status === 'DRAFT' && canEdit && (
            <button
              type="button"
              onClick={() => navigate(`/app/machinery/work-logs/${r.id}/edit`)}
              className="px-2 py-1 text-sm text-[#1F6F5C] hover:text-[#1a5a4a]"
            >
              Edit
            </button>
          )}
          {r.status === 'DRAFT' && canPost && (
            <button
              type="button"
              onClick={() => {
                setPostTarget(r);
                setPostingDate(new Date().toISOString().split('T')[0]);
              }}
              className="px-2 py-1 text-sm text-green-700 hover:text-green-800"
            >
              Post
            </button>
          )}
          {r.status === 'POSTED' && canPost && (
            <button
              type="button"
              onClick={() => {
                setReverseTarget(r);
                setPostingDate(new Date().toISOString().split('T')[0]);
                setReverseReason('');
              }}
              className="px-2 py-1 text-sm text-red-600 hover:text-red-700"
            >
              Reverse
            </button>
          )}
        </div>
      ),
    },
  ];

  const handlePost = async () => {
    if (!postTarget) return;
    await postM.mutateAsync({ id: postTarget.id, posting_date: postingDate });
    setPostTarget(null);
  };

  const handleReverse = async () => {
    if (!reverseTarget) return;
    await reverseM.mutateAsync({
      id: reverseTarget.id,
      posting_date: postingDate,
      reason: reverseReason.trim() || undefined,
    });
    setReverseTarget(null);
    setReverseReason('');
  };

  return (
    <div className="space-y-6 max-w-7xl">
      <AdvancedWorkflowBanner />
      <PageHeader
        title="Machine Usage"
        tooltip="Track how machines are used across work and field activities."
        description="Advanced/manual workflow. Kept for history, audit, and non-field exceptions."
        helper="Use Field Jobs for normal crop-field work. Only use Machine Usage for legacy records or exceptional/manual entries (for example: non-field/admin work or corrections that are not part of a Field Job)."
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Farm', to: '/app/dashboard' }, { label: 'Machinery Overview', to: '/app/machinery' }, { label: 'Machine Usage' }]}
        right={
          canEdit ? (
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={() => navigate('/app/crop-ops/field-jobs/new')}
                className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
              >
                New field job
              </button>
            </div>
          ) : undefined
        }
      />
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        <p className="font-medium">Advisory: avoid duplicate crop-work records</p>
        <p className="mt-1 text-amber-900/90">
          <span className="font-medium">Use Field Jobs</span> for normal crop-field work. Only create manual Machine Usage
          entries for legacy records or exceptional/manual cases. Recording the same event in both workflows can create
          duplicate operational and accounting records.
        </p>
      </div>

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4 space-y-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="flex flex-wrap gap-4 items-end">
          <div className="flex flex-col gap-1 min-w-[10rem]">
            <label className="text-sm font-medium text-gray-700">Status</label>
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setParam('status', e.target.value);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
              <option value="REVERSED">Reversed</option>
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Machine</label>
            <select
              value={machineId}
              onChange={(e) => {
                setMachineId(e.target.value);
                setParam('machine_id', e.target.value);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {machines?.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Crop cycle</label>
            <select
              value={cropCycleId}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setProjectId('');
                setParam('crop_cycle_id', e.target.value);
                setParam('project_id', '');
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {cropCycles?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[12rem]">
            <label className="text-sm font-medium text-gray-700">Field cycle</label>
            <select
              value={projectId}
              onChange={(e) => {
                setProjectId(e.target.value);
                setParam('project_id', e.target.value);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">All</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1 min-w-[10rem]">
            <label className="text-sm font-medium text-gray-700">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setParam('from', e.target.value);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
          <div className="flex flex-col gap-1 min-w-[10rem]">
            <label className="text-sm font-medium text-gray-700">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setParam('to', e.target.value);
              }}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            />
          </div>
        </div>

        <UsageSummary workLogs={workLogs ?? []} status={status} machineId={machineId} from={from} to={to} machines={machines ?? []} />
      </section>

      <div className="bg-white rounded-lg shadow overflow-x-auto">
        {isLoading ? (
          <div className="flex justify-center py-12">
            <LoadingSpinner size="lg" />
          </div>
        ) : (
          <DataTable
            data={(workLogs ?? []) as MachineWorkLog[]}
            columns={cols}
            onRowClick={(r) =>
              navigate(`/app/machinery/work-logs/${r.id}`, { state: { from: location.pathname + location.search } })
            }
            emptyMessage="No usage recorded yet. Add a usage entry to track machine work."
          />
        )}
      </div>

      <Modal
        isOpen={!!postTarget}
        onClose={() => setPostTarget(null)}
        title="Post usage entry"
      >
        <div className="space-y-4">
          <FormField label="Posting Date" required>
            <input
              type="date"
              value={postingDate}
              onChange={(e) => setPostingDate(e.target.value)}
              className="w-full px-3 py-2 border rounded"
            />
          </FormField>
          <div className="flex gap-2 pt-4">
            <button
              type="button"
              onClick={() => setPostTarget(null)}
              className="px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handlePost}
              disabled={postM.isPending}
              className="px-4 py-2 bg-green-600 text-white rounded"
            >
              {postM.isPending ? 'Posting…' : 'Post'}
            </button>
          </div>
        </div>
      </Modal>

      <Modal
        isOpen={!!reverseTarget}
        onClose={() => {
          setReverseTarget(null);
          setReverseReason('');
        }}
        title="Reverse usage entry"
      >
        <div className="space-y-4">
          <FormField label="Posting Date" required>
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
                setReverseTarget(null);
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

function UsageSummary({
  workLogs,
  status,
  machineId,
  from,
  to,
  machines,
}: {
  workLogs: MachineWorkLog[];
  status: string;
  machineId: string;
  from: string;
  to: string;
  machines: Array<{ id: string; name: string }>;
}) {
  const machineName = useMemo(() => {
    if (!machineId) return '';
    return machines.find((m) => m.id === machineId)?.name ?? '';
  }, [machineId, machines]);

  const bits: string[] = [];
  if (machineName) bits.push(machineName);
  if (status) bits.push(status.toLowerCase());
  if (from && to) bits.push(`${from} to ${to}`);
  else if (from) bits.push(`from ${from}`);
  else if (to) bits.push(`to ${to}`);

  return (
    <div className="text-sm text-gray-600">
      <span className="font-medium text-gray-900 tabular-nums">{workLogs.length}</span>{' '}
      {workLogs.length === 1 ? 'usage entry' : 'usage entries'}
      {bits.length ? <span className="text-gray-500"> · {bits.join(' · ')}</span> : null}
    </div>
  );
}
