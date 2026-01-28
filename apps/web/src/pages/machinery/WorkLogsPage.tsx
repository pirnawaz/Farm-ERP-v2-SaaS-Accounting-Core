import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
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

export default function WorkLogsPage() {
  const [status, setStatus] = useState('');
  const [machineId, setMachineId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

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
  const { formatMoney, formatDate } = useFormatting();
  const { hasRole } = useRole();

  const canPost = hasRole(['tenant_admin', 'accountant']);
  const canEdit = hasRole(['tenant_admin', 'accountant', 'operator']);

  const cols: Column<MachineWorkLog>[] = [
    { header: 'Work Log No', accessor: 'work_log_no' },
    { header: 'Machine', accessor: (r) => r.machine?.name ?? r.machine_id },
    { header: 'Work Date', accessor: (r) => (r.work_date ? formatDate(r.work_date) : '—') },
    { header: 'Project', accessor: (r) => r.project?.name ?? r.project_id },
    { header: 'Usage', accessor: (r) => (r.usage_qty != null ? String(r.usage_qty) : '—') },
    {
      header: 'Total',
      accessor: (r) => {
        const total = (r.lines ?? []).reduce((s, l) => s + parseFloat(String(l.amount || 0)), 0);
        return <span className="tabular-nums text-right block">{formatMoney(total)}</span>;
      },
    },
    {
      header: 'Status',
      accessor: (r) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            r.status === 'DRAFT'
              ? 'bg-yellow-100 text-yellow-800'
              : r.status === 'POSTED'
                ? 'bg-green-100 text-green-800'
                : 'bg-gray-100 text-gray-800'
          }`}
        >
          {r.status}
        </span>
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

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Work Logs"
        backTo="/app/machinery"
        breadcrumbs={[{ label: 'Machinery', to: '/app/machinery' }, { label: 'Work Logs' }]}
        right={
          canEdit ? (
            <button
              onClick={() => navigate('/app/machinery/work-logs/new')}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]"
            >
              New Work Log
            </button>
          ) : undefined
        }
      />
      <div className="flex gap-4 mb-4 flex-wrap">
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="px-3 py-2 border rounded text-sm"
        >
          <option value="">All statuses</option>
          <option value="DRAFT">DRAFT</option>
          <option value="POSTED">POSTED</option>
          <option value="REVERSED">REVERSED</option>
        </select>
        <select
          value={machineId}
          onChange={(e) => setMachineId(e.target.value)}
          className="px-3 py-2 border rounded text-sm"
        >
          <option value="">All machines</option>
          {machines?.map((m) => (
            <option key={m.id} value={m.id}>
              {m.name}
            </option>
          ))}
        </select>
        <select
          value={cropCycleId}
          onChange={(e) => {
            setCropCycleId(e.target.value);
            setProjectId('');
          }}
          className="px-3 py-2 border rounded text-sm"
        >
          <option value="">All crop cycles</option>
          {cropCycles?.map((c) => (
            <option key={c.id} value={c.id}>
              {c.name}
            </option>
          ))}
        </select>
        <select
          value={projectId}
          onChange={(e) => setProjectId(e.target.value)}
          className="px-3 py-2 border rounded text-sm"
        >
          <option value="">All projects</option>
          {projects?.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
        <input
          type="date"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
          className="px-3 py-2 border rounded text-sm"
          placeholder="From"
        />
        <input
          type="date"
          value={to}
          onChange={(e) => setTo(e.target.value)}
          className="px-3 py-2 border rounded text-sm"
          placeholder="To"
        />
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={workLogs ?? []}
          columns={cols}
          onRowClick={(r) =>
            navigate(`/app/machinery/work-logs/${r.id}`, { state: { from: location.pathname + location.search } })
          }
          emptyMessage="No work logs. Create one."
        />
      </div>

      <Modal
        isOpen={!!postTarget}
        onClose={() => setPostTarget(null)}
        title="Post Work Log"
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
        title="Reverse Work Log"
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
          <div className="flex gap-2 pt-4">
            <button
              type="button"
              onClick={() => {
                setReverseTarget(null);
                setReverseReason('');
              }}
              className="px-4 py-2 border rounded"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleReverse}
              disabled={reverseM.isPending}
              className="px-4 py-2 bg-red-600 text-white rounded"
            >
              {reverseM.isPending ? 'Reversing…' : 'Reverse'}
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
