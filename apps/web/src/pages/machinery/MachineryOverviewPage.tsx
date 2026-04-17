import { useMemo, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useChargesQuery, useMachinesQuery, useMaintenanceJobsQuery, useWorkLogsQuery } from '../../hooks/useMachinery';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';
import type { MachineMaintenanceJob, MachineWorkLog } from '../../types';

type ActivityRow = {
  id: string;
  date: string;
  type: 'Work Log' | 'Maintenance';
  title: string;
  detail?: string;
  to: string;
};

function isoDaysAgo(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

function KpiCard({
  label,
  value,
  hint,
  loading,
  foot,
}: {
  label: string;
  value: string | null;
  hint: string;
  loading: boolean;
  foot?: ReactNode;
}) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
      {loading ? (
        <div className="mt-3 flex h-9 items-center">
          <LoadingSpinner />
        </div>
      ) : (
        <p className="mt-2 text-2xl font-semibold tabular-nums text-gray-900">{value}</p>
      )}
      <p className="mt-1 text-xs text-gray-500">{hint}</p>
      {foot && <div className="mt-3 text-sm">{foot}</div>}
    </div>
  );
}

function ActionLink({
  to,
  variant,
  children,
}: {
  to: string;
  variant: 'primary' | 'secondary';
  children: ReactNode;
}) {
  const base =
    'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition-colors text-center';
  const styles =
    variant === 'primary'
      ? 'bg-[#1F6F5C] text-white shadow-sm hover:bg-[#1a5a4a]'
      : 'border border-gray-300 bg-white text-gray-800 hover:bg-gray-50';
  return (
    <Link to={to} className={`${base} ${styles}`}>
      {children}
    </Link>
  );
}

export default function MachineryOverviewPage() {
  const { formatDate } = useFormatting();

  const from30 = useMemo(() => isoDaysAgo(30), []);

  const { data: machines, isLoading: machinesLoading } = useMachinesQuery();
  const { data: workLogs30, isLoading: workLogsLoading } = useWorkLogsQuery({ from: from30 });
  const { data: jobsDraft, isLoading: jobsLoading } = useMaintenanceJobsQuery({ status: 'DRAFT' });
  const { data: charges30, isLoading: chargesLoading } = useChargesQuery({ from: from30 });

  const machineCount = machines?.length ?? 0;
  const workLogsCount = workLogs30?.length ?? 0;
  const openJobsCount = jobsDraft?.length ?? 0;
  const chargesCount = charges30?.length ?? 0;

  const hasNoData = machineCount === 0 && workLogsCount === 0 && openJobsCount === 0;

  const recentActivity = useMemo((): ActivityRow[] => {
    const wl = ((workLogs30 ?? []) as MachineWorkLog[])
      .filter((w) => !!w.work_date)
      .map((w) => ({
        id: `wl-${w.id}`,
        date: String(w.work_date || w.created_at).slice(0, 10),
        type: 'Work Log' as const,
        title: w.machine?.name || w.machine_id,
        detail: w.work_log_no ? `Work log ${w.work_log_no}` : undefined,
        to: `/app/machinery/work-logs/${w.id}`,
      }));

    const mj = ((jobsDraft ?? []) as MachineMaintenanceJob[])
      .filter((j) => !!j.job_date)
      .map((j) => ({
        id: `mj-${j.id}`,
        date: String(j.job_date).slice(0, 10),
        type: 'Maintenance' as const,
        title: j.machine?.name || j.machine_id,
        detail: j.job_no ? `Job ${j.job_no}` : undefined,
        to: `/app/machinery/maintenance-jobs/${j.id}`,
      }));

    const merged = [...wl, ...mj].sort((a, b) => String(b.date).localeCompare(String(a.date)));
    return merged.slice(0, 5);
  }, [workLogs30, jobsDraft]);

  return (
    <div className="space-y-10 max-w-6xl">
      {/* SECTION 1 — HEADER */ }
      <header>
        <h1 className="text-2xl font-bold text-gray-900">Machinery Overview</h1>
        <p className="mt-1 text-base text-gray-700 max-w-2xl">
          Track machines, usage, maintenance, machine profit, and machinery-related costs.
        </p>
        <div className="mt-4 flex flex-wrap gap-2">
          <Link
            to="/app/machinery/work-logs/new"
            className="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#1F6F5C] hover:bg-gray-50"
          >
            New machine usage
          </Link>
          <Link
            to="/app/machinery/reports/profitability"
            className="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#1F6F5C] hover:bg-gray-50"
          >
            Machine profit
          </Link>
          <Link
            to="/app/machinery/external-income"
            className="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#1F6F5C] hover:bg-gray-50"
          >
            External machine income
          </Link>
        </div>
      </header>

      {/* SECTION 5 — GETTING STARTED */ }
      {hasNoData && (
        <section className="rounded-xl border border-amber-200/80 bg-amber-50/90 px-5 py-4 text-amber-950 shadow-sm">
          <h2 className="text-sm font-semibold text-amber-950">Get started with machinery</h2>
          <p className="mt-1 text-sm text-amber-900/90">
            Add machines, then track maintenance jobs.
          </p>
          <ol className="mt-3 list-decimal list-inside space-y-1.5 text-sm text-amber-950">
            <li>Add machines — tractors, harvesters, and other assets.</li>
            <li>Track maintenance jobs — repairs and maintenance work.</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <Link
              to="/app/machinery/machines"
              className="inline-flex items-center justify-center rounded-lg bg-[#1F6F5C] px-4 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#1a5a4a]"
            >
              Add machine
            </Link>
            <Link
              to="/app/machinery/maintenance-jobs/new"
              className="inline-flex items-center justify-center rounded-lg border border-amber-300/80 bg-white px-4 py-2.5 text-sm font-medium text-amber-950 hover:bg-amber-100/50"
            >
              New maintenance job
            </Link>
          </div>
        </section>
      )}

      {/* SECTION 2 — KPI SUMMARY */ }
      <section aria-labelledby="machinery-kpi-heading">
        <h2 id="machinery-kpi-heading" className="sr-only">
          Summary
        </h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <KpiCard
            label="Total machines"
            loading={machinesLoading}
            value={machinesLoading ? null : String(machineCount)}
            hint="Machines set up in your farm"
            foot={
              <Link to="/app/machinery/machines" className="text-[#1F6F5C] hover:underline font-medium">
                View machines
              </Link>
            }
          />
          <KpiCard
            label="Open maintenance jobs"
            loading={jobsLoading}
            value={jobsLoading ? null : String(openJobsCount)}
            hint="Draft maintenance jobs not yet posted"
            foot={
              <Link to="/app/machinery/maintenance-jobs" className="text-[#1F6F5C] hover:underline font-medium">
                View maintenance
              </Link>
            }
          />
          <KpiCard
            label="Charges (30 days)"
            loading={chargesLoading}
            value={chargesLoading ? null : String(chargesCount)}
            hint="Machinery charges created in the last 30 days"
            foot={
              <Link to="/app/machinery/charges" className="text-[#1F6F5C] hover:underline font-medium">
                View charges
              </Link>
            }
          />
        </div>
      </section>

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-5 lg:gap-10">
        {/* SECTION 3 — RECENT MACHINERY ACTIVITY */ }
        <section className="lg:col-span-3" aria-labelledby="machinery-recent-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <h2 id="machinery-recent-heading" className="text-lg font-semibold text-gray-900">
                Recent machinery activity
              </h2>
              <div className="flex flex-wrap gap-2">
                <Link
                  to="/app/machinery/maintenance-jobs"
                  className="inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-[#1F6F5C] hover:bg-gray-50"
                >
                  View maintenance jobs
                </Link>
              </div>
            </div>

            {(workLogsLoading || jobsLoading) ? (
              <div className="flex justify-center py-10">
                <LoadingSpinner />
              </div>
            ) : recentActivity.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-600">
                No recent machinery activity.
              </p>
            ) : (
              <ul className="divide-y divide-gray-100">
                {recentActivity.map((a) => (
                  <li key={a.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4">
                    <div className="min-w-0 flex-1">
                      <Link to={a.to} className="text-sm font-medium text-[#1F6F5C] hover:underline truncate block">
                        {a.title}
                      </Link>
                      <p className="text-xs text-gray-500 mt-0.5">
                        {a.type}{a.detail ? ` · ${a.detail}` : ''}
                      </p>
                    </div>
                    <div className="shrink-0 text-sm text-gray-500 tabular-nums">
                      {formatDate(a.date)}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </section>

        {/* SECTION 4 — QUICK ACTIONS */ }
        <section className="lg:col-span-2" aria-labelledby="machinery-actions-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="machinery-actions-heading" className="text-lg font-semibold text-gray-900">
              Quick actions
            </h2>
            <p className="mt-1 text-xs text-gray-500">Shortcuts — full lists stay in the sidebar.</p>

            <div className="mt-4 space-y-2">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Common tasks</p>
              <div className="flex flex-col gap-2">
                <ActionLink to="/app/machinery/machines" variant="primary">Add machine</ActionLink>
                <ActionLink to="/app/machinery/maintenance-jobs/new" variant="primary">New Maintenance Job</ActionLink>
              </div>
            </div>

            <div className="mt-6 space-y-2">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-400">Lists</p>
              <div className="flex flex-wrap gap-2">
                <ActionLink to="/app/machinery/machines" variant="secondary">View Machines</ActionLink>
                <ActionLink to="/app/machinery/maintenance-jobs" variant="secondary">View maintenance jobs</ActionLink>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}

