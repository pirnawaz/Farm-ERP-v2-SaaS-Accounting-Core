import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { useProjects, useProject } from '../../hooks/useProjects';
import { PlanningInsightPanels } from '../../components/planning/PlanningInsightPanels';

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

/** Report hub entry: forecast + pre-harvest only (no plan form). */
export default function ProjectForecastDashboardPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: projects } = useProjects();
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [cropCycleId, setCropCycleId] = useState('');

  useEffect(() => {
    const p = searchParams.get('project_id');
    if (p) setProjectId(p);
  }, [searchParams]);

  const { data: projectMeta } = useProject(projectId || '');
  const projectCropId = projectMeta?.crop_cycle_id ?? '';

  const reportParams = useMemo(
    () => ({
      projectId,
      from: from || undefined,
      to: to || undefined,
      cropCycleId: cropCycleId || undefined,
    }),
    [projectId, from, to, cropCycleId]
  );

  return (
    <div className="max-w-6xl mx-auto px-4 py-6 space-y-6">
      <PageHeader
        title="Field forecast"
        description="Planned versus actual results, plus a simple before-harvest profit snapshot."
        backTo="/app/reports"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Reports', to: '/app/reports' },
          { label: 'Field forecast' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div>
            <label className="block text-sm text-gray-700 mb-1">Field</label>
            <select
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
            >
              <option value="">Select…</option>
              {(projects ?? []).map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">From</label>
            <input
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">To</label>
            <input
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md"
            />
          </div>
          <div>
            <label className="block text-sm text-gray-700 mb-1">Crop season</label>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              disabled={!projectId}
            >
              <option value="">All on this field</option>
              {projectCropId ? <option value={projectCropId}>This season only</option> : null}
            </select>
          </div>
        </div>
      </div>

      {projectId ? (
        <PlanningInsightPanels
          projectId={reportParams.projectId}
          from={reportParams.from}
          to={reportParams.to}
          cropCycleId={reportParams.cropCycleId}
          formatMoney={(v) => formatMoney(v)}
        />
      ) : (
        <p className="text-center text-sm text-gray-500 py-8">Select a field to load the dashboard.</p>
      )}
    </div>
  );
}
