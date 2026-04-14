import { Link } from 'react-router-dom';
import { useMemo } from 'react';
import { useActivityTypes } from '../../hooks/useCropOps';
import { useFieldJobs } from '../../hooks/useFieldJobs';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import type { FieldJob } from '../../types';

function KpiCard({
  label,
  value,
  hint,
  to,
}: {
  label: string;
  value: string;
  hint?: string;
  to?: string;
}) {
  const body = (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm hover:border-[#1F6F5C]/30 transition-colors">
      <div className="text-sm font-medium text-gray-600">{label}</div>
      <div className="mt-2 text-3xl font-semibold text-gray-900 tabular-nums">{value}</div>
      {hint ? <div className="mt-1 text-xs text-gray-500">{hint}</div> : null}
    </div>
  );
  return to ? (
    <Link to={to} className="block focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] rounded-xl">
      {body}
    </Link>
  ) : (
    body
  );
}

function ActionLink({
  to,
  children,
  variant,
}: {
  to: string;
  children: React.ReactNode;
  variant: 'primary' | 'secondary';
}) {
  const base =
    'inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C] w-full text-center';
  const styles =
    variant === 'primary'
      ? 'bg-[#1F6F5C] text-white hover:bg-[#1a5a4a]'
      : 'border border-gray-200 bg-white text-[#1F6F5C] hover:bg-gray-50';
  return (
    <Link to={to} className={`${base} ${styles}`}>
      {children}
    </Link>
  );
}

export default function CropOpsDashboardPage() {
  const thirtyDaysAgoIso = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
  }, []);

  const { data: activityTypes, isLoading: typesLoading } = useActivityTypes({ is_active: true });
  const { data: fieldJobs, isLoading: jobsLoading } = useFieldJobs();

  const workTypeCount = (activityTypes ?? []).filter((t) => t.is_active).length;
  const allJobs = fieldJobs ?? [];
  const jobsLast30 = useMemo(
    () => allJobs.filter((j) => (j.job_date || '').slice(0, 10) >= thirtyDaysAgoIso),
    [allJobs, thirtyDaysAgoIso],
  );

  const recentFieldJobs = useMemo(() => {
    return [...allJobs]
      .sort(
        (a, b) =>
          String(b.job_date || '').localeCompare(String(a.job_date || '')) ||
          String(b.created_at || '').localeCompare(String(a.created_at || '')),
      )
      .slice(0, 5);
  }, [allJobs]);

  const describeRow = (j: FieldJob) => {
    const typeName = j.crop_activity_type?.name || j.crop_activity_type_id;
    const cycle = j.crop_cycle?.name || j.crop_cycle_id;
    const proj = j.project?.name || j.project_id;
    const bits = [typeName, cycle, proj].filter(Boolean);
    const meta = bits.join(' · ') || '—';
    const note = (j.notes || '').trim();
    const short = note.length > 80 ? `${note.slice(0, 77)}…` : note || j.doc_no || meta;
    return { meta, desc: short };
  };

  const hasNoData = workTypeCount === 0 && allJobs.length === 0;

  if (typesLoading || jobsLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-10 max-w-6xl">
      <header>
        <h1 className="text-2xl font-bold text-gray-900">Crop Ops Overview</h1>
        <p className="mt-1 text-base text-gray-700 max-w-2xl">
          Set up work types and run field jobs—the primary workflow for field work, inputs, labour, and machinery.
        </p>
        <p className="mt-1 text-sm text-gray-500 max-w-2xl">
          Use field jobs to capture operations in one place before posting to accounting.
        </p>
      </header>

      {hasNoData ? (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-5">
          <div className="text-base font-semibold text-amber-900">Get started with crop ops</div>
          <ol className="mt-2 list-decimal list-inside text-sm text-amber-900/90 space-y-1">
            <li>Add work types</li>
            <li>Create a field job</li>
            <li>Add lines and post when ready</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <ActionLink to="/app/crop-ops/activity-types" variant="primary">
              Add work type
            </ActionLink>
            <ActionLink to="/app/crop-ops/field-jobs/new" variant="secondary">
              New field job
            </ActionLink>
          </div>
        </section>
      ) : null}

      <section aria-label="Crop ops summary">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <KpiCard label="Work types (active)" value={String(workTypeCount)} to="/app/crop-ops/activity-types" />
          <KpiCard label="Field jobs" value={String(allJobs.length)} hint="All field job documents" to="/app/crop-ops/field-jobs" />
          <KpiCard
            label="Field jobs (30 days)"
            value={String(jobsLast30.length)}
            hint="Jobs with work date in the last 30 days"
            to="/app/crop-ops/field-jobs"
          />
          <KpiCard
            label="Posted field jobs"
            value={String(allJobs.filter((j) => j.status === 'POSTED').length)}
            hint="Posted jobs"
            to="/app/crop-ops/field-jobs"
          />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-10">
        <section className="lg:col-span-2" aria-labelledby="crop-ops-recent-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <h2 id="crop-ops-recent-heading" className="text-lg font-semibold text-gray-900">
                Recent field jobs
              </h2>
              <Link to="/app/crop-ops/field-jobs" className="text-sm font-medium text-[#1F6F5C] hover:underline">
                View all field jobs
              </Link>
            </div>

            {recentFieldJobs.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-600">No field jobs yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100">
                  <thead>
                    <tr className="text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                      <th className="py-3 pr-4">Date</th>
                      <th className="py-3 pr-4">Field / cycle / type</th>
                      <th className="py-3 pr-0">Job</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {recentFieldJobs.map((j) => {
                      const { meta, desc } = describeRow(j);
                      return (
                        <tr key={j.id} className="text-sm">
                          <td className="py-3 pr-4 tabular-nums text-gray-700">{(j.job_date || '').slice(0, 10)}</td>
                          <td className="py-3 pr-4 text-gray-700">
                            <span className="block max-w-[20rem] truncate" title={meta}>
                              {meta || '—'}
                            </span>
                          </td>
                          <td className="py-3 pr-0 text-gray-700">
                            <Link to={`/app/crop-ops/field-jobs/${j.id}`} className="text-[#1F6F5C] hover:underline">
                              <span className="block max-w-[24rem] truncate" title={desc}>
                                {desc}
                              </span>
                            </Link>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </section>

        <section className="lg:col-span-1" aria-labelledby="crop-ops-actions-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="crop-ops-actions-heading" className="text-lg font-semibold text-gray-900">
              Quick actions
            </h2>
            <p className="mt-1 text-xs text-gray-500">Shortcuts — full lists are in the sidebar.</p>

            <div className="mt-4 flex flex-col gap-2">
              <ActionLink to="/app/crop-ops/field-jobs/new" variant="primary">
                New field job
              </ActionLink>
              <ActionLink to="/app/crop-ops/activity-types" variant="primary">
                Add work type
              </ActionLink>
            </div>

            <div className="mt-6 flex flex-col gap-2">
              <ActionLink to="/app/farm-activity" variant="secondary">
                Farm activity timeline
              </ActionLink>
              <ActionLink to="/app/crop-ops/field-jobs" variant="secondary">
                View field jobs
              </ActionLink>
              <ActionLink to="/app/crop-ops/activity-types" variant="secondary">
                View work types
              </ActionLink>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
