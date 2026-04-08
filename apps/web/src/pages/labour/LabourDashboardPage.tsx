import { Link } from 'react-router-dom';
import { useMemo } from 'react';
import { useWorkers, useWorkLogs, usePayablesOutstanding } from '../../hooks/useLabour';
import { LoadingSpinner } from '../../components/LoadingSpinner';
import { useFormatting } from '../../hooks/useFormatting';

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
    'inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1F6F5C]';
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

export default function LabourDashboardPage() {
  const { data: workers, isLoading: workersLoading } = useWorkers();
  const { data: workLogs, isLoading: logsLoading } = useWorkLogs();
  const { data: payables, isLoading: payablesLoading } = usePayablesOutstanding();
  const { formatMoney } = useFormatting();

  const workerCount = (workers ?? []).length;
  const logs = workLogs ?? [];
  const outstandingTotal = (payables || []).reduce((s, r) => s + parseFloat(r.payable_balance || '0'), 0);

  const thirtyDaysAgoIso = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d.toISOString().slice(0, 10);
  }, []);

  const logsLast30Days = useMemo(
    () => logs.filter((l) => (l.work_date || '').slice(0, 10) >= thirtyDaysAgoIso),
    [logs, thirtyDaysAgoIso]
  );

  const recentLogs = useMemo(() => {
    return [...logs]
      .sort((a, b) => (b.work_date || '').localeCompare(a.work_date || '') || (b.created_at || '').localeCompare(a.created_at || ''))
      .slice(0, 5);
  }, [logs]);

  const hasNoData = workerCount === 0 && logs.length === 0;
  const hasWorkersNoLogs = workerCount > 0 && logs.length === 0;

  if (workersLoading || logsLoading || payablesLoading) {
    return (
      <div className="flex justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    );
  }

  return (
    <div className="space-y-10 max-w-6xl">
      <header>
        <h1 className="text-2xl font-bold text-gray-900">Labour Overview</h1>
        <p className="mt-1 text-base text-gray-700 max-w-2xl">Track workers, labour activity, and amounts owed.</p>
        <p className="mt-1 text-sm text-gray-500 max-w-2xl">Use this page to see labour activity and manage your workforce.</p>
      </header>

      {hasNoData ? (
        <section className="rounded-xl border border-amber-200 bg-amber-50 p-5">
          <div className="text-base font-semibold text-amber-900">Get started with labour</div>
          <ol className="mt-2 list-decimal list-inside text-sm text-amber-900/90 space-y-1">
            <li>Add workers</li>
            <li>Record work logs</li>
            <li>Review payables</li>
          </ol>
          <div className="mt-4 flex flex-wrap gap-2">
            <ActionLink to="/app/labour/workers" variant="primary">Add workers</ActionLink>
            <ActionLink to="/app/labour/work-logs/new" variant="secondary">New work log</ActionLink>
          </div>
        </section>
      ) : null}

      <section aria-label="Labour summary">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <KpiCard label="Workers" value={String(workerCount)} to="/app/labour/workers" />
          <KpiCard
            label="Work logs (30 days)"
            value={String(logsLast30Days.length)}
            hint="Work recorded in the last 30 days"
            to="/app/labour/work-logs"
          />
          <KpiCard
            label="Payables"
            value={formatMoney(outstandingTotal)}
            hint="Amount owed to workers"
            to="/app/labour/payables"
          />
        </div>
      </section>

      {hasWorkersNoLogs ? (
        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
          No work recorded yet. Add a work log to track labour activity.
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-10">
        <section className="lg:col-span-2" aria-labelledby="labour-recent-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 pb-3">
              <h2 id="labour-recent-heading" className="text-lg font-semibold text-gray-900">
                Recent labour activity
              </h2>
              <Link
                to="/app/labour/work-logs"
                className="text-sm font-medium text-[#1F6F5C] hover:underline"
              >
                View Work Logs
              </Link>
            </div>

            {recentLogs.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-600">
                No work logs yet.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-100">
                  <thead>
                    <tr className="text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                      <th className="py-3 pr-4">Worker</th>
                      <th className="py-3 pr-4">Work</th>
                      <th className="py-3 pr-4 text-right">Units</th>
                      <th className="py-3 pr-0">Date</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {recentLogs.map((l) => (
                      <tr key={l.id} className="text-sm">
                        <td className="py-3 pr-4 text-gray-900 font-medium">
                          <Link to={`/app/labour/work-logs/${l.id}`} className="text-[#1F6F5C] hover:underline">
                            {l.worker?.name || '—'}
                          </Link>
                        </td>
                        <td className="py-3 pr-4 text-gray-700">
                          <span className="block max-w-[22rem] truncate" title={l.notes || l.doc_no}>
                            {l.notes || l.doc_no || '—'}
                          </span>
                        </td>
                        <td className="py-3 pr-4 text-right tabular-nums text-gray-700">{l.units ?? '—'}</td>
                        <td className="py-3 pr-0 tabular-nums text-gray-700">{(l.work_date || '').slice(0, 10)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </section>

        <section className="lg:col-span-1" aria-labelledby="labour-actions-heading">
          <div className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="labour-actions-heading" className="text-lg font-semibold text-gray-900">
              Quick actions
            </h2>
            <p className="mt-1 text-xs text-gray-500">Shortcuts — full lists stay in the sidebar.</p>

            <div className="mt-4 space-y-2">
              <ActionLink to="/app/labour/workers" variant="primary">Add Worker</ActionLink>
              <ActionLink to="/app/labour/work-logs/new" variant="primary">New Work Log</ActionLink>
            </div>

            <div className="mt-6 flex flex-col gap-2">
              <ActionLink to="/app/labour/workers" variant="secondary">View Workers</ActionLink>
              <ActionLink to="/app/labour/work-logs" variant="secondary">View Work Logs</ActionLink>
              <ActionLink to="/app/labour/payables" variant="secondary">View Payables</ActionLink>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
