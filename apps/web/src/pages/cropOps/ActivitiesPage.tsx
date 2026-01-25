import { useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useActivities, useActivityTypes } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import type { CropActivity } from '../../types';

export default function ActivitiesPage() {
  const [status, setStatus] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [activityTypeId, setActivityTypeId] = useState('');
  const [landParcelId, setLandParcelId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data: activities, isLoading } = useActivities({
    status: status || undefined,
    crop_cycle_id: cropCycleId || undefined,
    project_id: projectId || undefined,
    activity_type_id: activityTypeId || undefined,
    land_parcel_id: landParcelId || undefined,
    from: from || undefined,
    to: to || undefined,
  });
  const { data: activityTypes } = useActivityTypes();
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);
  const { data: landParcels } = useLandParcels();
  const navigate = useNavigate();
  const location = useLocation();
  const { formatMoney } = useFormatting();

  const totalCost = (a: CropActivity) => {
    const inputsTotal = (a.inputs || []).reduce((s, i) => s + parseFloat(String(i.line_total || 0)), 0);
    const labourTotal = (a.labour || []).reduce((s, l) => s + parseFloat(String(l.amount || 0)), 0);
    return inputsTotal + labourTotal;
  };

  const cols: Column<CropActivity>[] = [
    { header: 'Doc No', accessor: 'doc_no' },
    { header: 'Type', accessor: (r) => r.type?.name || r.activity_type_id },
    { header: 'Activity Date', accessor: 'activity_date' },
    { header: 'Crop Cycle', accessor: (r) => r.crop_cycle?.name || r.crop_cycle_id },
    { header: 'Project', accessor: (r) => r.project?.name || r.project_id },
    { header: 'Total', accessor: (r) => formatMoney(totalCost(r)) },
    {
      header: 'Status',
      accessor: (r) => (
        <span
          className={`px-2 py-1 rounded text-xs ${
            r.status === 'DRAFT' ? 'bg-yellow-100 text-yellow-800' :
            r.status === 'POSTED' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
          }`}
        >
          {r.status}
        </span>
      ),
    },
  ];

  if (isLoading) return <div className="flex justify-center py-12"><LoadingSpinner size="lg" /></div>;

  return (
    <div>
      <PageHeader
        title="Crop Ops → Activities"
        backTo="/app/crop-ops"
        breadcrumbs={[{ label: 'Crop Ops', to: '/app/crop-ops' }, { label: 'Activities' }]}
        right={
          <button onClick={() => navigate('/app/crop-ops/activities/new')} className="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            New Activity
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
        <select value={cropCycleId} onChange={(e) => { setCropCycleId(e.target.value); setProjectId(''); }} className="px-3 py-2 border rounded text-sm">
          <option value="">All crop cycles</option>
          {cropCycles?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select value={projectId} onChange={(e) => setProjectId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All projects</option>
          {projects?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
        <select value={activityTypeId} onChange={(e) => setActivityTypeId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All types</option>
          {activityTypes?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        <select value={landParcelId} onChange={(e) => setLandParcelId(e.target.value)} className="px-3 py-2 border rounded text-sm">
          <option value="">All land parcels</option>
          {landParcels?.map((p) => <option key={p.id} value={p.id}>{p.name || p.id}</option>)}
        </select>
        <input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="From" />
        <input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="px-3 py-2 border rounded text-sm" placeholder="To" />
      </div>
      <div className="bg-white rounded-lg shadow">
        <DataTable
          data={activities || []}
          columns={cols}
          onRowClick={(r) => navigate(`/app/crop-ops/activities/${r.id}`, { state: { from: location.pathname + location.search } })}
          emptyMessage="No activities. Create one."
        />
      </div>
    </div>
  );
}
