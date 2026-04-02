import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useHarvests } from '../../hooks/useHarvests';
import { useCropCycles } from '../../hooks/useCropCycles';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { Badge } from '../../components/Badge';
import { useFormatting } from '../../hooks/useFormatting';
import type { Harvest } from '../../types';

export default function HarvestsPage() {
  const [status, setStatus] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data: harvests, isLoading } = useHarvests({
    status: status || undefined,
    crop_cycle_id: cropCycleId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: cropCycles } = useCropCycles();
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();

  const totalQty = (h: Harvest) => {
    return (h.lines || []).reduce((s, l) => s + parseFloat(String(l.quantity || 0)), 0);
  };

  const cols: Column<Harvest>[] = [
    { header: 'Harvest No', accessor: (r) => r.harvest_no || '—' },
    { header: 'Harvest Date', accessor: (r) => formatDate(r.harvest_date) },
    { header: 'Crop Cycle', accessor: (r) => r.crop_cycle?.name || r.crop_cycle_id },
    { header: 'Project', accessor: (r) => r.project?.name ?? '—' },
    { header: 'Total Qty', accessor: (r) => totalQty(r).toFixed(3) },
    {
      header: 'Status',
      accessor: (r) => (
        <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
          {r.status}
        </Badge>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title="Harvests"
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops', to: '/app/crop-ops' },
          { label: 'Harvests' },
        ]}
        right={
          <button type="button" onClick={() => navigate('/app/harvests/new')} className="w-full sm:w-auto px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a]">
            New Harvest
          </button>
        }
      />
      <div className="space-y-4">
        <div className="flex flex-wrap gap-4 items-end">
          <select value={status} onChange={(e) => setStatus(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All statuses</option>
            <option value="DRAFT">DRAFT</option>
            <option value="POSTED">POSTED</option>
            <option value="REVERSED">REVERSED</option>
          </select>
          <select value={cropCycleId} onChange={(e) => setCropCycleId(e.target.value)} className="px-3 py-2 border rounded text-sm">
            <option value="">All crop cycles</option>
            {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
          <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="From" />
          <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="To" />
        </div>
        <div className="bg-white rounded-lg shadow">
          {isLoading ? (
            <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>
          ) : (
            <DataTable
              data={(harvests ?? []) as Harvest[]}
              columns={cols}
              onRowClick={(r) => navigate(`/app/harvests/${r.id}`, { state: { from: location.pathname + location.search } })}
              emptyMessage="No harvests. Create one."
            />
          )}
        </div>
      </div>
    </div>
  );
}
