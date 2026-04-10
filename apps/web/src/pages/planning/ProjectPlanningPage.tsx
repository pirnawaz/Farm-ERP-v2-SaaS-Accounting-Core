import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { PageHeader } from '../../components/PageHeader';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import { useProjects, useProject } from '../../hooks/useProjects';
import { useCreateProjectPlan, useProjectPlansList } from '../../hooks/usePlanning';
import { PlanningInsightPanels } from '../../components/planning/PlanningInsightPanels';
import type { CreateProjectPlanPayload } from '../../types';

const COST_TYPES = [
  { type: 'INPUT' as const, label: 'Inputs & stock' },
  { type: 'LABOUR' as const, label: 'Labour' },
  { type: 'MACHINERY' as const, label: 'Machinery & fuel' },
];

function startOfYear(): string {
  const d = new Date();
  return new Date(d.getFullYear(), 0, 1).toISOString().split('T')[0];
}
function today(): string {
  return new Date().toISOString().split('T')[0];
}

export default function ProjectPlanningPage() {
  const { formatMoney } = useFormatting();
  const [searchParams] = useSearchParams();
  const { data: projects } = useProjects();
  const [projectId, setProjectId] = useState('');
  const [from, setFrom] = useState(startOfYear);
  const [to, setTo] = useState(today);
  const [cropCycleId, setCropCycleId] = useState('');
  const [planName, setPlanName] = useState('Season plan');
  const [costs, setCosts] = useState<Record<string, string>>({ INPUT: '', LABOUR: '', MACHINERY: '' });
  const [yieldRows, setYieldRows] = useState([{ qty: '', unitVal: '' }]);

  useEffect(() => {
    const p = searchParams.get('project_id');
    if (p) setProjectId(p);
  }, [searchParams]);

  const { data: projectMeta } = useProject(projectId || '');
  const projectCropId = projectMeta?.crop_cycle_id ?? '';

  const { data: existingPlans, isLoading: plansLoading } = useProjectPlansList(
    { project_id: projectId || undefined },
    { enabled: !!projectId }
  );

  const createPlan = useCreateProjectPlan();

  const reportParams = useMemo(
    () => ({
      projectId,
      from: from || undefined,
      to: to || undefined,
      cropCycleId: cropCycleId || undefined,
    }),
    [projectId, from, to, cropCycleId]
  );

  const handleSave = () => {
    if (!projectId || !projectMeta) return;

    const costPayload = COST_TYPES.map(({ type }) => {
      const raw = costs[type]?.trim();
      if (raw === '') {
        return null;
      }
      const n = Number(raw);
      if (Number.isNaN(n)) {
        return null;
      }
      return { cost_type: type, expected_cost: n };
    }).filter((x): x is { cost_type: 'INPUT' | 'LABOUR' | 'MACHINERY'; expected_cost: number } => x !== null);

    const yieldsPayload = yieldRows
      .map((row) => {
        const q = row.qty.trim() === '' ? null : Number(row.qty);
        const u = row.unitVal.trim() === '' ? null : Number(row.unitVal);
        if (q === null || u === null || Number.isNaN(q) || Number.isNaN(u)) {
          return null;
        }
        return { expected_quantity: q, expected_unit_value: u };
      })
      .filter((x): x is { expected_quantity: number; expected_unit_value: number } => x !== null);

    const payload: CreateProjectPlanPayload = {
      name: planName.trim() || 'Plan',
      project_id: projectId,
      crop_cycle_id: projectMeta.crop_cycle_id,
      status: 'ACTIVE',
      ...(costPayload.length ? { costs: costPayload } : {}),
      ...(yieldsPayload.length ? { yields: yieldsPayload } : {}),
    };
    createPlan.mutate(payload);
  };

  const canSave = !!projectId && !!projectMeta && planName.trim().length > 0;

  return (
    <div className="max-w-6xl mx-auto px-4 py-6 space-y-6">
      <PageHeader
        title="Field plan & forecast"
        description="Write down what you expect to spend and harvest. The cards below show how that compares to posted activity."
        backTo="/app/projects"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Fields', to: '/app/projects' },
          { label: 'Plan & forecast' },
        ]}
      />

      <div className="bg-white rounded-lg shadow p-6 space-y-4">
        <h2 className="text-sm font-semibold text-gray-900">Field and dates</h2>
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
            <label className="block text-sm text-gray-700 mb-1">Crop season filter</label>
            <select
              value={cropCycleId}
              onChange={(e) => setCropCycleId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-[#1F6F5C]"
              disabled={!projectId}
            >
              <option value="">All activity on this field</option>
              {projectCropId ? <option value={projectCropId}>This crop season only</option> : null}
            </select>
          </div>
        </div>
        <p className="text-xs text-gray-500">
          Dates apply to the forecast cards. Leave crop filter open to include every season on this field.
        </p>
      </div>

      <div className="bg-white rounded-lg shadow p-6 space-y-6">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Your expected numbers</h2>
          <p className="text-sm text-gray-600 mt-1">
            Rough totals are fine. Saving marks this plan as active so the forecast uses it.
          </p>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Plan name</label>
          <input
            type="text"
            value={planName}
            onChange={(e) => setPlanName(e.target.value)}
            className="w-full max-w-md px-3 py-2 border border-gray-300 rounded-md"
            placeholder="e.g. Wheat 2026"
          />
        </div>

        <div>
          <h3 className="text-sm font-semibold text-gray-800 mb-2">Expected costs</h3>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {COST_TYPES.map(({ type, label }) => (
              <div key={type}>
                <label className="block text-xs text-gray-600 mb-1">{label}</label>
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  inputMode="decimal"
                  value={costs[type] ?? ''}
                  onChange={(e) => setCosts((c) => ({ ...c, [type]: e.target.value }))}
                  className="w-full px-3 py-2 border border-gray-300 rounded-md tabular-nums"
                  placeholder="0"
                />
              </div>
            ))}
          </div>
        </div>

        <div>
          <div className="flex items-center justify-between gap-2 mb-2">
            <h3 className="text-sm font-semibold text-gray-800">Expected harvest value</h3>
            <button
              type="button"
              onClick={() => setYieldRows((rows) => [...rows, { qty: '', unitVal: '' }])}
              className="text-sm text-[#1F6F5C] font-medium hover:underline"
            >
              + Add row
            </button>
          </div>
          <p className="text-xs text-gray-500 mb-3">Quantity × expected price per unit for each crop or batch.</p>
          <div className="space-y-3">
            {yieldRows.map((row, i) => (
              <div key={i} className="flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-[120px]">
                  <label className="block text-xs text-gray-600 mb-1">Quantity</label>
                  <input
                    type="number"
                    min={0}
                    step="any"
                    inputMode="decimal"
                    value={row.qty}
                    onChange={(e) =>
                      setYieldRows((rows) => rows.map((r, j) => (j === i ? { ...r, qty: e.target.value } : r)))
                    }
                    className="w-full px-3 py-2 border border-gray-300 rounded-md tabular-nums"
                    placeholder="0"
                  />
                </div>
                <div className="flex-1 min-w-[120px]">
                  <label className="block text-xs text-gray-600 mb-1">Price per unit</label>
                  <input
                    type="number"
                    min={0}
                    step="any"
                    inputMode="decimal"
                    value={row.unitVal}
                    onChange={(e) =>
                      setYieldRows((rows) => rows.map((r, j) => (j === i ? { ...r, unitVal: e.target.value } : r)))
                    }
                    className="w-full px-3 py-2 border border-gray-300 rounded-md tabular-nums"
                    placeholder="0"
                  />
                </div>
                {yieldRows.length > 1 ? (
                  <button
                    type="button"
                    className="text-sm text-rose-600 pb-2"
                    onClick={() => setYieldRows((rows) => rows.filter((_, j) => j !== i))}
                  >
                    Remove
                  </button>
                ) : null}
              </div>
            ))}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            disabled={!canSave || createPlan.isPending}
            onClick={handleSave}
            className="px-4 py-2 rounded-md bg-[#1F6F5C] text-white font-medium disabled:opacity-50 disabled:cursor-not-allowed hover:bg-[#185a4a]"
          >
            {createPlan.isPending ? 'Saving…' : 'Save plan'}
          </button>
          {!projectId && <span className="text-sm text-gray-500">Select a field first.</span>}
        </div>

        {projectId && (
          <div className="border-t border-gray-100 pt-4">
            <h3 className="text-sm font-semibold text-gray-800 mb-2">Saved plans for this field</h3>
            {plansLoading ? (
              <LoadingSpinner size="sm" />
            ) : !existingPlans?.length ? (
              <p className="text-sm text-gray-500">No plans yet. Save one above.</p>
            ) : (
              <ul className="divide-y divide-gray-100 border border-gray-100 rounded-md">
                {existingPlans.map((pl) => (
                  <li key={pl.id} className="px-3 py-2 flex flex-wrap justify-between gap-2 text-sm">
                    <span className="font-medium text-gray-900">{pl.name}</span>
                    <span className="text-gray-500">{pl.status}</span>
                    <span className="text-xs text-gray-400 w-full">
                      Updated {pl.updated_at ? new Date(pl.updated_at).toLocaleString() : '—'}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
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
        <p className="text-center text-sm text-gray-500 py-8">Choose a field to load the forecast and pre-harvest cards.</p>
      )}
    </div>
  );
}
