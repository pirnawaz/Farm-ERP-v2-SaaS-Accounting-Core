import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useWorkLogs, useWorkers } from '../../hooks/useLabour';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { LabWorkLog } from '../../types';

export default function WorkLogsPage() {
  const [status, setStatus] = useState('');
  const [workerId, setWorkerId] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data: workLogs, isLoading } = useWorkLogs({
    status: status || undefined,
    worker_id: workerId || undefined,
    crop_cycle_id: cropCycleId || undefined,
    project_id: projectId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: workers } = useWorkers({});
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);
  const navigate = useNavigate();
  const location = useLocation();
  const { formatMoney, formatDate } = useFormatting();

  const cols: Column<LabWorkLog>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'Worker', accessor: (r) => r.worker?.name || r.worker_id },
    { header: 'Work Date', accessor: (r) => formatDate(r.work_date) },
    { header: 'Project', accessor: (r) => r.project?.name || r.project_id },
    { header: 'Units', accessor: 'units' },
    { header: 'Rate', accessor: (r) => <span className="tabular-nums text-right block">{formatMoney(r.rate)}</span> },
    { header: 'Amount', accessor: (r) => <span className="tabular-nums text-right block">{formatMoney(r.amount)}</span> },
    { header: 'Status', accessor: (r) => (
      <span className={`px-2 py-1 rounded text-xs ${
        r.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
        r.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
      }`}>{r.status}</span>
    ) },
  ];

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Work Logs"
        backTo="/app/labour"
        breadcrumbs={[{ label: 'Labour', to: '/app/labour' }, { label: 'Work Logs' }]}
        right={
          <button onClick={() => navigate('/app/labour/work-logs/new')} className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">
            New Work Log
          </button>
        }
      />
      <div className="flex gap-4 mb-4 flex-wrap">
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All statuses</option>
          <option value="DRAFT">DRAFT</option>
          <option value="POSTED">POSTED</option>
          <option value="REVERSED">REVERSED</option>
        </select>
        <select value={workerId} onChange={(e) => setWorkerId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All workers</option>
          {workers?.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}
        </select>
        <select value={cropCycleId} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="px-3 py-2 border rounded text-sm">
          <option value="">All crop cycles</option>
          {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All projects</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="From" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="To" />
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={(workLogs ?? []) as LabWorkLog[]}
          columns={cols}
          onRowClick={(r) => navigate(`/app/labour/work-logs/${r.id}`, { state: { from: location.pathname + location.search } })}
          emptyMessage="No work logs. Create one."
        />
      </div>
    </div>
  );
}
