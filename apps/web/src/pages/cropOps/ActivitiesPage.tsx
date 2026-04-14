import { useMemo, useState } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useActivities, useActivityTypes } from '../../hooks/useCropOps';
import { useCropCycles } from '../../hooks/useCropCycles';
import { useProjects } from '../../hooks/useProjects';
import { useLandParcels } from '../../hooks/useLandParcels';
import { DataTable, type Column } from '../../components/DataTable';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { PageHeader } from '../../components/PageHeader';
import { useFormatting } from '../../hooks/useFormatting';
import { Badge } from '../../components/Badge';
import { AdvancedWorkflowBanner } from '../../components/workflow/AdvancedWorkflowBanner';
import type { CropActivity } from '../../types';

function whereSummary(r: CropActivity): string {
  const parts = [r.crop_cycle?.name, r.project?.name, r.land_parcel?.name || r.land_parcel_id].filter(Boolean) as string[];
  return parts.length ? parts.join(' · ') : '—';
}

function notesPreview(notes: string | null | undefined): string {
  const t = (notes || '').trim();
  if (!t) return '—';
  return t.length > 120 ? `${t.slice(0, 117)}…` : t;
}

export default function ActivitiesPage() {
  const [status, setStatus] = useState('');
  const [cropCycleId, setCropCycleId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [activityTypeId, setActivityTypeId] = useState('');
  const [landParcelId, setLandParcelId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const filters = useMemo(
    () => ({
      status: status || undefined,
      crop_cycle_id: cropCycleId || undefined,
      project_id: projectId || undefined,
      activity_type_id: activityTypeId || undefined,
      land_parcel_id: landParcelId || undefined,
      from: from || undefined,
      to: to || undefined,
    }),
    [status, cropCycleId, projectId, activityTypeId, landParcelId, from, to],
  );

  const { data: activities, isLoading } = useActivities(filters);
  const { data: activityTypes } = useActivityTypes();
  const { data: cropCycles } = useCropCycles();
  const { data: projects } = useProjects(cropCycleId || undefined);
  const { data: landParcels } = useLandParcels();
  const navigate = useNavigate();
  const location = useLocation();
  const { formatDate } = useFormatting();

  const hasActiveFilters = useMemo(
    () =>
      !!(status || cropCycleId || projectId || activityTypeId || landParcelId || from || to),
    [status, cropCycleId, projectId, activityTypeId, landParcelId, from, to],
  );

  const clearFilters = () => {
    setStatus('');
    setCropCycleId('');
    setProjectId('');
    setActivityTypeId('');
    setLandParcelId('');
    setFrom('');
    setTo('');
  };

  const summaryParts = useMemo(() => {
    const bits: string[] = [];
    if (status) {
      bits.push(
        status === 'DRAFT' ? 'Draft' : status === 'POSTED' ? 'Posted' : status === 'REVERSED' ? 'Reversed' : status,
      );
    }
    if (cropCycleId) {
      const name = cropCycles?.find((c) => c.id === cropCycleId)?.name;
      bits.push(name ? `crop cycle (${name})` : 'selected crop cycle');
    }
    if (projectId) {
      const name = projects?.find((p) => p.id === projectId)?.name;
      bits.push(name ? `project (${name})` : 'selected project');
    }
    if (activityTypeId) {
      const name = activityTypes?.find((t) => t.id === activityTypeId)?.name;
      bits.push(name ? `work type (${name})` : 'selected work type');
    }
    if (landParcelId) {
      const p = landParcels?.find((x) => x.id === landParcelId);
      bits.push(p?.name ? `land (${p.name})` : 'selected land parcel');
    }
    if (from) bits.push(`from ${from}`);
    if (to) bits.push(`to ${to}`);
    return bits;
  }, [status, cropCycleId, projectId, activityTypeId, landParcelId, from, to, cropCycles, projects, activityTypes, landParcels]);

  const summaryLine = useMemo(() => {
    const n = activities?.length ?? 0;
    const noun = n === 1 ? 'field work log' : 'field work logs';
    const base = hasActiveFilters ? `${n} ${noun} (filtered)` : `${n} ${noun}`;
    if (!hasActiveFilters || summaryParts.length === 0) return base;
    return `${base} · ${summaryParts.join(' · ')}`;
  }, [activities?.length, hasActiveFilters, summaryParts]);

  const cols: Column<CropActivity>[] = useMemo(
    () => [
      {
        header: 'Date',
        accessor: (r) => (
          <span className="tabular-nums text-gray-900">{formatDate(r.activity_date, { variant: 'medium' })}</span>
        ),
        cellClassName: 'whitespace-nowrap',
      },
      {
        header: 'Work type',
        accessor: (r) => <span className="text-gray-900 font-medium">{r.type?.name || r.activity_type_id}</span>,
      },
      {
        header: 'Crop cycle / field / project',
        accessor: (r) => (
          <span className="text-gray-700 max-w-xs lg:max-w-md block truncate" title={whereSummary(r)}>
            {whereSummary(r)}
          </span>
        ),
      },
      {
        header: 'Notes',
        accessor: (r) => (
          <span className="text-gray-600 max-w-[14rem] lg:max-w-xl block truncate" title={(r.notes || '').trim() || undefined}>
            {notesPreview(r.notes)}
          </span>
        ),
      },
      {
        header: 'Reference',
        accessor: (r) => <span className="tabular-nums text-gray-700">{r.doc_no}</span>,
      },
      {
        header: 'Status',
        accessor: (r) => (
          <Badge variant={r.status === 'DRAFT' ? 'warning' : r.status === 'POSTED' ? 'success' : 'neutral'}>
            {r.status === 'DRAFT' ? 'Draft' : r.status === 'POSTED' ? 'Posted' : 'Reversed'}
          </Badge>
        ),
      },
    ],
    [formatDate],
  );

  const rows = (activities ?? []) as CropActivity[];

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-7xl">
      <AdvancedWorkflowBanner />
      <PageHeader
        title="Field Work Logs"
        description="Legacy workflow for field activity. Kept for historical records and audit."
        helper="Use Field Jobs for normal crop-field work (inputs, labour, machinery, and posting). Only use Field Work Logs for legacy records or exceptional/manual cases where a Field Job is not appropriate."
        backTo="/app/crop-ops"
        breadcrumbs={[
          { label: 'Farm', to: '/app/dashboard' },
          { label: 'Crop Ops Overview', to: '/app/crop-ops' },
          { label: 'Field Work Logs' },
        ]}
        right={
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => navigate('/app/crop-ops/field-jobs/new')}
              className="px-4 py-2 bg-[#1F6F5C] text-white rounded-md hover:bg-[#1a5a4a] text-sm font-medium"
            >
              New field job
            </button>
          </div>
        }
      />
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
        <p className="font-medium">Advisory: avoid duplicate crop-work records</p>
        <p className="mt-1 text-amber-900/90">
          <span className="font-medium">Use Field Jobs</span> for normal crop-field work. Only create a Field Work Log for
          legacy records or exceptional/manual cases. Recording the same real-world event in both workflows can create
          duplicate operational and accounting records.
        </p>
      </div>

      <section aria-label="Filters" className="rounded-xl border border-gray-200 bg-gray-50/80 p-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-3">
          <h2 className="text-sm font-semibold text-gray-900">Filters</h2>
          <button
            type="button"
            onClick={clearFilters}
            disabled={!hasActiveFilters}
            className="text-sm font-medium text-[#1F6F5C] hover:underline disabled:opacity-40 disabled:cursor-not-allowed disabled:no-underline"
          >
            Clear filters
          </button>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <div>
            <label htmlFor="fwl-status" className="block text-xs font-medium text-gray-600 mb-1">
              Status
            </label>
            <select
              id="fwl-status"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All statuses</option>
              <option value="DRAFT">Draft</option>
              <option value="POSTED">Posted</option>
              <option value="REVERSED">Reversed</option>
            </select>
          </div>
          <div>
            <label htmlFor="fwl-cycle" className="block text-xs font-medium text-gray-600 mb-1">
              Crop cycle
            </label>
            <select
              id="fwl-cycle"
              value={cropCycleId}
              onChange={(e) => {
                setCropCycleId(e.target.value);
                setProjectId('');
              }}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All crop cycles</option>
              {cropCycles?.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="fwl-project" className="block text-xs font-medium text-gray-600 mb-1">
              Field (project)
            </label>
            <select
              id="fwl-project"
              value={projectId}
              onChange={(e) => setProjectId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All fields</option>
              {projects?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="fwl-wtype" className="block text-xs font-medium text-gray-600 mb-1">
              Work type
            </label>
            <select
              id="fwl-wtype"
              value={activityTypeId}
              onChange={(e) => setActivityTypeId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All work types</option>
              {activityTypes?.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="fwl-land" className="block text-xs font-medium text-gray-600 mb-1">
              Land parcel
            </label>
            <select
              id="fwl-land"
              value={landParcelId}
              onChange={(e) => setLandParcelId(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            >
              <option value="">All land parcels</option>
              {landParcels?.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name || p.id}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="fwl-from" className="block text-xs font-medium text-gray-600 mb-1">
              From (work date)
            </label>
            <input
              id="fwl-from"
              type="date"
              value={from}
              onChange={(e) => setFrom(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            />
          </div>
          <div>
            <label htmlFor="fwl-to" className="block text-xs font-medium text-gray-600 mb-1">
              To (work date)
            </label>
            <input
              id="fwl-to"
              type="date"
              value={to}
              onChange={(e) => setTo(e.target.value)}
              className="w-full px-3 py-2 border border-gray-200 rounded-md text-sm bg-white"
            />
          </div>
        </div>
      </section>

      <div className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800">
        <span className="font-medium text-gray-900">{summaryLine}</span>
      </div>

      {rows.length === 0 ? (
        <div className="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-6 py-14 text-center">
          <h3 className="text-base font-semibold text-gray-900">
            {hasActiveFilters ? 'No field work logs match these filters' : 'No field work logs yet'}
          </h3>
          <p className="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            {hasActiveFilters
              ? 'Try changing filters or clear them to see all records.'
              : 'Log field work to start tracking crop operations.'}
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            {hasActiveFilters ? (
              <button
                type="button"
                onClick={clearFilters}
                className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50"
              >
                Clear filters
              </button>
            ) : null}
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm border border-gray-100 overflow-hidden">
          <DataTable
            data={rows}
            columns={cols}
            onRowClick={(r) =>
              navigate(`/app/crop-ops/activities/${r.id}`, { state: { from: location.pathname + location.search } })
            }
            emptyMessage=""
          />
        </div>
      )}
    </div>
  );
}
