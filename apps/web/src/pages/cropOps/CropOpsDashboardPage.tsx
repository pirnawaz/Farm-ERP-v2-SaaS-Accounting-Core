import { Link } from 'react-router-dom';
import { useMemo } from 'react';
import { useActivities, useActivityTypes } from '../../hooks/useCropOps';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import type { CropActivity } from '../../types';

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
  const { data: activities, isLoading: activitiesLoading } = useActivities();

  const workTypeCount = (activityTypes ?? []).filter((t) => t.is_active).length;
  const allActivities = activities ?? [];
  const logsLast30 = useMemo(
    () => allActivities.filter((a) => (a.activity_date || '').slice(0, 10) >= thirtyDaysAgoIso),
    [allActivities, thirtyDaysAgoIso],
  );

  const recentFieldActivity = useMemo(() => {
    return [...allActivities]
      .sort(
        (a, b) =>
          String(b.activity_date || '').localeCompare(String(a.activity_date || '')) ||
          String(b.created_at || '').localeCompare(String(a.created_at || '')),
      )
      .slice(0, 5);
  }, [allActivities]);

  const describeRow = (a: CropActivity) => {
    const typeName = a.type?.name || a.activity_type_id;
    const cycle = a.crop_cycle?.name || a.crop_cycle_id;
    const proj = a.project?.name || a.project_id;
    const bits = [typeName, cycle, proj].filter(Boolean);
    const meta = bits.join(' · ') || '—';
    const note = (a.notes || '').trim();
    const short =
      note.length > 80 ? `${note.slice(0, 77)}…` : note || a.doc_no || meta;
    return { meta, desc: short };
  };

  const hasNoData = workTypeCount === 0 && allActivities.length === 0;

  if (typesLoading || activitiesLoading) {
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
          Track field work, field activity, and crop operation setup.
        </p>
        <p className="mt-1 text-sm text-gray-500 max-w-2xl">
          Use this page to manage field work and related crop operations. This is field and crop activity — for people and
          payables, use Labour.
        </p>
      </header>

      {hasNoData ? (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-5">
          <div className="text-base font-semibold text-amber-900">Get started with crop ops</div>
          <ol className="mt-2 list-decimal list-inside text-sm text-amber-900/90 space-y-1">
            <li>Add work types</li>
            <li>Record field work</li>
            <li>Review field activity</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <ActionLink to="/app/crop-ops/activity-types" variant="primary">
              Add work type
            </ActionLink>
            <ActionLink to="/app/crop-ops/activities/new" variant="secondary">
              Log field work
            </ActionLink>
          </div>
        </section>
      ) : null}

      <section aria-label="Crop ops summary">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <KpiCard label="Work types (active)" value={String(workTypeCount)} to="/app/crop-ops/activity-types" />
          <KpiCard label="Field work records" value={String(allActivities.length)} hint="All field work documents" to="/app/crop-ops/activities" />
          <KpiCard
            label="Field work logs (30 days)"
            value={String(logsLast30.length)}
            hint="Records with work date in the last 30 days"
            to="/app/crop-ops/activities"
          />
          <KpiCard
            label="Posted field work"
            value={String(allActivities.filter((a) => a.status === 'POSTED').length)}
            hint="Posted activities"
            to="/app/crop-ops/activities"
          />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-10">
        <section className="lg:col-span-2" aria-labelledby="crop-ops-recent-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <h2 id="crop-ops-recent-heading" className="text-lg font-semibold text-gray-900">
                Recent field activity
              </h2>
              <Link to="/app/crop-ops/activities" className="text-sm font-medium text-[#1F6F5C] hover:underline">
                View Field Work Logs
              </Link>
            </div>

            {recentFieldActivity.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-600">No field work recorded yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100">
                  <thead>
                    <tr className="text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                      <th className="py-3 pr-4">Date</th>
                      <th className="py-3 pr-4">Field / cycle / type</th>
                      <th className="py-3 pr-0">Activity</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {recentFieldActivity.map((a) => {
                      const { meta, desc } = describeRow(a);
                      return (
                        <tr key={a.id} className="text-sm">
                          <td className="py-3 pr-4 tabular-nums text-gray-700">{(a.activity_date || '').slice(0, 10)}</td>
                          <td className="py-3 pr-4 text-gray-700">
                            <span className="block max-w-[20rem] truncate" title={meta}>
                              {meta || '—'}
                            </span>
                          </td>
                          <td className="py-3 pr-0 text-gray-700">
                            <Link to={`/app/crop-ops/activities/${a.id}`} className="text-[#1F6F5C] hover:underline">
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
              <ActionLink to="/app/crop-ops/activities/new" variant="primary">
                Log field work
              </ActionLink>
              <ActionLink to="/app/crop-ops/activity-types" variant="primary">
                Add work type
              </ActionLink>
            </div>

            <div className="mt-6 flex flex-col gap-2">
              <ActionLink to="/app/crop-ops/activities" variant="secondary">
                View Field Work Logs
              </ActionLink>
              <ActionLink to="/app/crop-ops/activity-types" variant="secondary">
                View Work Types
              </ActionLink>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
